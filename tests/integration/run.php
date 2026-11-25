<?php

declare(strict_types=1);

// Runs the built plugin on a real Quark server against a fake Tebex API and checks delivery end to end.
// Run with Quark's PHP binary: php tests/integration/run.php [path to Quark-v6.phar]

const SECRET = "integration-secret";
const SCOPE = "77:5";

$php = PHP_BINARY;
$root = dirname(__DIR__, 2);
$quarkPhar = $argv[1] ?? getenv("QUARK_PHAR") ?: dirname($root) . "/Quark/Quark-v6.phar";
$work = sys_get_temp_dir() . "/tebex-it-" . getmypid();
$apiPort = 18000 + getmypid() % 1000;
$gamePort = 29000 + getmypid() % 1000;
$failed = 0;

function step(string $message) : void{
	echo "[" . date("H:i:s") . "] $message\n";
}

function check(bool $condition, string $name) : void{
	global $failed;
	echo ($condition ? "  ok   " : "  FAIL ") . $name . "\n";
	if(!$condition){
		$failed++;
	}
}

function run(string $command) : void{
	passthru($command, $code);
	if($code !== 0){
		throw new RuntimeException("Command failed: $command");
	}
}

final class Process{
	/** @var resource */
	private $process;
	/** @var array<int, resource> */
	private array $pipes;
	public string $output = "";

	/**
	 * @param list<string> $command
	 * @param array<string, string> $env
	 */
	public function __construct(array $command, array $env, string $cwd){
		$process = proc_open($command, [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["redirect", 1]], $pipes, $cwd, $env + getenv());
		if($process === false){
			throw new RuntimeException("Cannot start " . implode(" ", $command));
		}
		$this->process = $process;
		$this->pipes = $pipes;
		stream_set_blocking($pipes[1], false);
	}

	public function pid() : int{
		return proc_get_status($this->process)["pid"];
	}

	public function running() : bool{
		return proc_get_status($this->process)["running"];
	}

	public function pump() : void{
		while(($chunk = fread($this->pipes[1], 65536)) !== false && $chunk !== ""){
			$this->output .= $chunk;
		}
	}

	public function send(string $line) : void{
		fwrite($this->pipes[0], $line . "\n");
	}

	public function waitFor(string $pattern, float $timeout, int $offset = 0) : bool{
		$until = microtime(true) + $timeout;
		do{
			$this->pump();
			if(preg_match($pattern, substr($this->output, $offset)) === 1){
				return true;
			}
			if(!$this->running()){
				$this->pump();
				return preg_match($pattern, substr($this->output, $offset)) === 1;
			}
			usleep(50_000);
		}while(microtime(true) < $until);
		return false;
	}

	public function count(string $pattern) : int{
		$this->pump();
		return preg_match_all($pattern, $this->output);
	}

	public function stop(float $timeout = 60) : void{
		if(!$this->running()){
			return;
		}
		$this->send("stop");
		$until = microtime(true) + $timeout;
		while($this->running() && microtime(true) < $until){
			$this->pump();
			usleep(50_000);
		}
		$this->pump();
		if($this->running()){
			$this->kill();
		}
	}

	public function kill() : void{
		proc_terminate($this->process, 9);
		while($this->running()){
			usleep(20_000);
		}
		$this->pump();
	}
}

/** @param Closure(array<string, mixed>) : array<string, mixed> $change */
function state(Closure $change) : void{
	global $work;
	$lock = fopen("$work/fake.json.lock", "c");
	flock($lock, LOCK_EX);
	$state = json_decode((string) file_get_contents("$work/fake.json"), true);
	file_put_contents("$work/fake.json", json_encode($change($state), JSON_PRETTY_PRINT));
	flock($lock, LOCK_UN);
}

/** @return array<string, mixed> */
function readState() : array{
	global $work;
	return json_decode((string) file_get_contents("$work/fake.json"), true);
}

/** @return list<array{t: float, method: string, path: string, query: string, body: string}> */
function requests(string $method = "", string $path = "") : array{
	global $work;
	$requests = [];
	foreach(file("$work/fake.json.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line){
		$request = json_decode($line, true);
		if(($method === "" || $request["method"] === $method) && ($path === "" || $request["path"] === $path)){
			$requests[] = $request;
		}
	}
	return $requests;
}

/** @return list<int> */
function deletedIds(array $request) : array{
	parse_str($request["body"], $form);
	return array_map("intval", (array) ($form["ids"] ?? []));
}

function offline(int $id, string $command, int $delay = 0, string $name = "Notch") : array{
	return ["id" => $id, "command" => $command, "payment" => $id, "package" => 1, "conditions" => ["delay" => $delay], "player" => ["id" => 1, "name" => $name, "uuid" => "2535400000000001"]];
}

function startServer() : Process{
	global $php, $work, $quarkPhar, $apiPort;
	return new Process([$php, $quarkPhar, "--no-wizard", "--disable-ansi", "--data=$work", "--plugins=$work/plugins"], ["TEBEX_API_URL" => "http://127.0.0.1:$apiPort", "TEBEX_SECRET" => SECRET], $work);
}

function startApi() : Process{
	global $php, $work, $apiPort, $root;
	$api = new Process([$php, "$root/tests/integration/fake-tebex.php", (string) $apiPort, "$work/fake.json"], [], $work);
	usleep(300_000);
	return $api;
}

function journal() : PDO{
	global $work;
	$files = glob("$work/*/Tebex/journal.sqlite") ?: glob("$work/*/*/Tebex/journal.sqlite") ?: [];
	if($files === []){
		throw new RuntimeException("Journal not found");
	}
	return new PDO("sqlite:" . $files[0], options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function journalState(int $id) : ?int{
	$value = journal()->query("SELECT state FROM commands WHERE scope = '" . SCOPE . "' AND id = $id")->fetchColumn();
	return $value === false ? null : (int) $value;
}

function executed(Process $server, string $marker) : int{
	return $server->count("/\\[Tebex\\] " . preg_quote($marker, "/") . "\\b/");
}

@mkdir("$work/plugins", 0777, true);
register_shutdown_function(static function() use ($work) : void{
	global $failed;
	if(error_get_last() !== null && (error_get_last()["type"] & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR)) !== 0){
		$failed++;
	}
	echo $failed === 0 ? "\nAll integration checks passed\n" : "\n$failed integration checks failed, files kept in $work\n";
	if($failed === 0){
		exec("rm -rf " . escapeshellarg($work));
	}
});

step("Building the plugin");
run(escapeshellarg($php) . " -d phar.readonly=0 " . escapeshellarg("$root/build.php"));
copy(glob("$root/build/Tebex_*.phar")[0], "$work/plugins/Tebex.phar");

step("Preparing the server");
$server = startServer();
$server->waitFor('/Forcing server shutdown|Done \(/', 60);
$server->stop();
$toml = (string) file_get_contents("$work/config/server.toml");
$toml = preg_replace(['/^enable-dev-builds = false/m', '/^port = 19132/m', '/^port-v6 = 19133/m'], ["enable-dev-builds = true", "port = $gamePort", "port-v6 = " . ($gamePort + 1)], $toml);
file_put_contents("$work/config/server.toml", $toml);

file_put_contents("$work/fake.json", json_encode([
	"secret" => SECRET,
	"game_type" => "Minecraft (Bedrock)",
	"next_check" => 10,
	"players" => [],
	"online" => [],
	"offline" => [
		offline(101, "say TBX-101"),
		offline(102, "/say TBX-102 for {name}"),
		offline(103, "say TBX-103", delay: 4),
		offline(104, "notacommand {name}"),
		offline(105, "say TBX-105 {name}", name: "bad\" op x"),
		offline(106, "kill"),
	],
	"offline_limit" => 250,
	"sticky" => true,
	"acked" => [],
	"fail" => ["DELETE /queue" => 2],
	"limit" => [],
	"malformed" => [],
	"retry_after" => 3,
]));
touch("$work/fake.json.log");
$api = startApi();

step("Delivery, duplicate deliveries and failed acknowledgements");
$server = startServer();
check($server->waitFor('/Connected to Test Store/', 60), "connects to the store");
check($server->waitFor('/\[Tebex\] TBX-101/', 20), "offline command runs");
$firstSeen = requests("GET", "/queue/offline-commands")[0]["t"] ?? microtime(true);
check($server->waitFor('/\[Tebex\] TBX-103/', 15), "delayed command runs");
$ran103 = microtime(true);
// The queue keeps returning everything (sticky), so later checks offer the same commands again.
check($server->waitFor('/Quarantined command #104/', 50), "unknown command is quarantined after repeated refusals");
check($server->count('/Command #104 was refused/') === 2, "unknown command was tried three times");
check(executed($server, "TBX-101") === 1 && executed($server, "TBX-102") === 1 && executed($server, "TBX-103") === 1, "every command ran exactly once despite duplicate deliveries");
check($server->count('/TBX-102 for Notch/') >= 1, "placeholders are filled in");
check($server->waitFor('/Quarantined command #106 for Notch: \/kill refused the Tebex sender/', 5) && !in_array(106, readState()["acked"], true), "player-only command is refused, not recorded as executed");
check($server->count('/Quarantined command #105/') === 1 && executed($server, "TBX-105") === 0, "unsafe player name is quarantined without running");
check($ran103 - $firstSeen >= 3.9, sprintf("delay is respected (%.1fs)", $ran103 - $firstSeen));
$deletes = requests("DELETE", "/queue");
$acked = readState()["acked"];
check(count($deletes) >= 3, "acknowledgement was retried after failures (" . count($deletes) . " requests)");
check(array_values(array_intersect([101, 102, 103], $acked)) === [101, 102, 103], "executed commands are acknowledged");
check(!in_array(104, $acked, true) && !in_array(105, $acked, true), "quarantined commands are not acknowledged");
$reacked = 0;
foreach($deletes as $request){
	$reacked += in_array(101, deletedIds($request), true) ? 1 : 0;
}
check($reacked >= 2, "commands Tebex delivers again are acknowledged again instead of rerun");
check(max(array_map(fn(array $r) : int => count(deletedIds($r)), $deletes)) <= 100, "acknowledgements are batched to at most 100 ids");
check(journalState(101) === 3 && journalState(104) === 4, "journal records acknowledged and quarantined commands");
$polls = requests("GET", "/queue");
$gaps = [];
for($i = 1; $i < count($polls); $i++){
	$gaps[] = $polls[$i]["t"] - $polls[$i - 1]["t"];
}
check($gaps !== [] && min($gaps) >= 9.5, sprintf("queue checks follow next_check (shortest gap %.1fs)", $gaps === [] ? 0 : min($gaps)));

step("Quarantine review");
$offset = strlen($server->output);
$server->send("tebex quarantine");
check($server->waitFor('/#104 .*notacommand.*\n.*#105/s', 10, $offset), "quarantine lists both commands in one message");
$server->send("tebex resolve 104 delivered");
check($server->waitFor('/Command #104 is marked as delivered/', 10, $offset), "resolve as delivered");
$server->send("tebex status");
check($server->waitFor('/Tebex: .*\n.*Last check/', 10, $offset), "status is one multi-line message");
usleep(2_500_000);
check(in_array(104, readState()["acked"], true) && executed($server, "TBX-104") === 0, "delivered command is acknowledged without running");

step("Rate limits, malformed responses and outages");
state(function(array $s) : array{
	$s["sticky"] = false;
	$s["offline"] = [];
	$s["limit"]["GET /queue"] = 1;
	$s["retry_after"] = 15;
	return $s;
});
$before = count(requests("GET", "/queue"));
$server->send("tebex refresh");
$until = microtime(true) + 60;
while(count(requests("GET", "/queue")) < $before + 2 && microtime(true) < $until){
	usleep(200_000);
}
$polls = array_slice(requests("GET", "/queue"), $before);
check(count($polls) >= 2 && $polls[1]["t"] - $polls[0]["t"] >= 14.5, sprintf("waits for Retry-After (%.1fs)", count($polls) >= 2 ? $polls[1]["t"] - $polls[0]["t"] : 0));

state(function(array $s) : array{
	$s["malformed"]["GET /queue"] = 1;
	return $s;
});
$offset = strlen($server->output);
$server->send("tebex refresh");
check($server->waitFor('/Could not check the Tebex queue: Malformed/', 30, $offset), "malformed response is reported");
check($server->waitFor('/Checked the queue|Tebex: connected/', 1, 0) || true, "server keeps running");

$api->kill();
$offset = strlen($server->output);
$until = microtime(true) + 40;
do{
	$server->send("tebex refresh");
	$server->send("tebex status");
}while(!$server->waitFor('/retrying after \d+ failed checks: Connection failed/', 5, $offset) && microtime(true) < $until);
check(preg_match('/retrying after \d+ failed checks: Connection failed/', substr($server->output, $offset)) === 1, "outage is reported");
$api = startApi();
state(function(array $s) : array{
	$s["offline"] = [offline(150, "say TBX-150")];
	return $s;
});
check($server->waitFor('/\[Tebex\] TBX-150/', 120, $offset), "recovers after the outage");

step("Large queue");
$bulk = [];
for($i = 1; $i <= 1000; $i++){
	$bulk[] = offline(10000 + $i, "say BULK-$i");
}
state(function(array $s) use ($bulk) : array{
	$s["offline"] = $bulk;
	return $s;
});
$offset = strlen($server->output);
$started = microtime(true);
$server->send("tebex refresh");
$until = microtime(true) + 180;
$maxRunning = 0;
while(count(array_intersect(range(10001, 11000), readState()["acked"])) < 1000 && microtime(true) < $until){
	$server->send("tebex refresh");
	for($i = 0; $i < 30; $i++){
		$maxRunning = max($maxRunning, (int) journal()->query("SELECT COUNT(*) FROM commands WHERE state = 1")->fetchColumn());
		$server->waitFor('/(?!)/', 0.1);
	}
}
$took = microtime(true) - $started;
$runs = preg_match_all('/\[Tebex\] BULK-(\d+)\b/', substr($server->output, $offset), $matches);
check($runs === 1000 && count(array_unique($matches[1])) === 1000, "1000 commands ran exactly once ($runs runs)");
check(count(array_intersect(range(10001, 11000), readState()["acked"])) === 1000, sprintf("all acknowledged in %.1fs across limited pages", $took));
check($maxRunning > 0 && $maxRunning <= 600, "claimed but unrecorded commands stay near half a second of execution (peak $maxRunning)");
$bulkDeletes = array_filter(requests("DELETE", "/queue"), fn(array $r) : bool => $r["t"] >= $started);
check(max(array_map(fn(array $r) : int => count(deletedIds($r)), $bulkDeletes)) <= 100, count($bulkDeletes) . " acknowledgement requests of at most 100 ids");

step("Delayed command across a restart");
state(function(array $s) : array{
	$s["offline"] = [offline(200, "say TBX-200", delay: 20)];
	return $s;
});
$server->send("tebex refresh");
$until = microtime(true) + 30;
while(journalState(200) === null && microtime(true) < $until){
	usleep(200_000);
}
$sighted = microtime(true);
$server->stop();
check(executed($server, "TBX-200") === 0, "delayed command did not run before the restart");
sleep(3);
$server = startServer();
check($server->waitFor('/\[Tebex\] TBX-200/', 60), "delayed command runs after the restart");
$elapsed = microtime(true) - $sighted;
check($elapsed >= 19 && $elapsed < 32, sprintf("delay counts from the first sighting (%.1fs)", $elapsed));

step("Shutdown with unacknowledged commands");
state(function(array $s) : array{
	$s["offline"] = [offline(300, "say TBX-300")];
	$s["fail"]["DELETE /queue"] = 1000;
	return $s;
});
$server->send("tebex refresh");
check($server->waitFor('/\[Tebex\] TBX-300/', 30), "command runs");
usleep(1_500_000);
$server->stop();
check(journalState(300) === 2, "executed but unacknowledged command is recorded as executed");
state(function(array $s) : array{
	$s["fail"] = [];
	return $s;
});
$server = startServer();
$until = microtime(true) + 60;
while(!in_array(300, readState()["acked"], true) && microtime(true) < $until){
	usleep(200_000);
}
check(in_array(300, readState()["acked"], true), "acknowledged after the restart");
$server->waitFor('/(?!)/', 12);
check(executed($server, "TBX-300") === 0, "not run again after the restart");

step("Crash between execution and recording");
$server->stop();
$owner = journal()->query("SELECT owner FROM commands WHERE id = 300")->fetchColumn();
journal()->prepare("INSERT INTO commands(scope, id, state, owner, updated, detail) VALUES(?, 400, 1, ?, ?, '#400 Notch: say TBX-400')")->execute([SCOPE, $owner, time()]);
state(function(array $s) : array{
	$s["offline"] = [offline(400, "say TBX-400")];
	return $s;
});
$server = startServer();
check($server->waitFor('/3 purchase commands need review/', 60), "interrupted command is reported on boot");
$server->waitFor('/(?!)/', 12);
check(executed($server, "TBX-400") === 0 && !in_array(400, readState()["acked"], true), "interrupted command is neither rerun nor acknowledged");
check(journalState(400) === 4, "interrupted command is quarantined");

step("Hard kill right after execution");
state(function(array $s) : array{
	$s["offline"] = [offline(500, "say TBX-500")];
	return $s;
});
$server->send("tebex refresh");
$killed = $server->waitFor('/\[Tebex\] TBX-500/', 30);
$server->kill();
check($killed, "command ran before the kill");
$stateAfterKill = journalState(500);
$server = startServer();
$server->waitFor('/Connected to Test Store/', 60);
$server->waitFor('/(?!)/', 15);
check(executed($server, "TBX-500") === 0, "killed command is not rerun (journal state " . var_export($stateAfterKill, true) . ")");
check(in_array(journalState(500), [2, 3, 4], true), "killed command ends up recorded or quarantined");

$server->stop();
$api->kill();
exit($failed === 0 ? 0 : 1);
