<?php

declare(strict_types=1);

use Trix\Tebex\api\TebexApi;
use Trix\Tebex\api\TebexException;
use Trix\Tebex\Executor;
use Trix\Tebex\journal\Journal;
use Trix\Tebex\journal\JournalTask;
use Trix\Tebex\QueuedCommand;
use Trix\Tebex\Settings;
use Trix\Tebex\Store;

require dirname(__DIR__) . "/vendor/autoload.php";
$quark = getenv("QUARK_DIR") ?: dirname(__DIR__, 2) . "/Quark";
require $quark . "/vendor/autoload.php";

$failed = 0;
$count = 0;
function check(bool $condition, string $name) : void{
	global $failed, $count;
	$count++;
	if(!$condition){
		$failed++;
		echo "FAIL $name\n";
	}
}

function throws(Closure $fn, string $class) : bool{
	try{
		$fn();
	}catch(Throwable $e){
		return $e instanceof $class;
	}
	return false;
}

// Placeholders
check(Executor::render("give {name} diamond 1", "Steve", "") === "give Steve diamond 1", "plain name");
check(Executor::render("/give {player} diamond", "Big Steve", "") === "give \"Big Steve\" diamond", "spaced name is quoted, slash removed");
check(Executor::render("say \"{username}\" bought", "Big Steve", "") === "say \"Big Steve\" bought", "already quoted placeholder");
check(Executor::render("rank set {id} vip", "Steve", "2535412345678901") === "rank set 2535412345678901 vip", "xuid placeholder");
check(Executor::render("rank {uuid} {name}", "Steve", "2535-ab") === "rank 2535-ab Steve", "uuid placeholder");
check(Executor::render("give {name} x", "a\" op hacker", "") === null, "quote injection rejected");
check(Executor::render("give {name} x", "Steve;op", "") === null, "symbol in name rejected");
check(Executor::render("give {name} x", "Steve  Two", "") === null, "double space rejected");
check(Executor::render("give {name} x", str_repeat("a", 33), "") === null, "long name rejected");
check(Executor::render("say hi\nop x", "Steve", "") === null, "newline rejected");
check(Executor::render("   ", "Steve", "") === null, "empty command rejected");
check(Executor::render("say {id}", "Steve", "1 op") === null, "bad uuid rejected");
check(Executor::render("say thanks", "bad\" name", "x y") === "say thanks", "unused placeholders are not validated");
check(Executor::render("give {name} {unknown}", ".Floodgate_1", "") === "give .Floodgate_1 {unknown}", "unknown placeholders stay");

// Responses
check(TebexApi::decode(204, null, "", null) === null, "empty 204");
check(TebexApi::decode(200, null, '{"a":1}', null) === ["a" => 1], "json body");
check(throws(fn() => TebexApi::decode(200, null, "{oops", null), TebexException::class), "malformed json rejected");
try{
	TebexApi::decode(403, null, '{"error_code":403,"error_message":"No server could be found with the specified secret key."}', null);
	check(false, "403 throws");
}catch(TebexException $e){
	check($e->isUnauthorized() && !$e->isTransient() && str_contains($e->getMessage(), "No server"), "403 message and kind");
}
try{
	TebexApi::decode(429, "30", "", null);
	check(false, "429 throws");
}catch(TebexException $e){
	check($e->isRateLimited() && $e->isTransient() && $e->retryAfter === 30, "429 retry after");
}
try{
	TebexApi::decode(0, null, "", "28 Operation timed out\nX-Tebex-Secret: no");
	check(false, "network error throws");
}catch(TebexException $e){
	check($e->status === 0 && $e->isTransient() && !str_contains($e->getMessage(), "\n"), "network error is transient and single line");
}
check(throws(fn() => TebexApi::decode(502, null, "<html>bad gateway</html>", null), TebexException::class), "html error page");
$now = 1_700_000_000;
check(TebexApi::retryAfter("120", $now) === 120, "retry after seconds");
check(TebexApi::retryAfter(gmdate("D, d M Y H:i:s", $now + 45) . " GMT", $now) === 45, "retry after date");
check(TebexApi::retryAfter("99999", $now) === 3600, "retry after capped");
check(TebexApi::retryAfter("garbage", $now) === null, "retry after garbage");
$ok = true;
for($i = 1; $i <= 20; $i++){
	$delay = TebexApi::backoff($i, 5, 300);
	$ceiling = min(300, 5 * 2 ** ($i - 1));
	$ok = $ok && $delay >= intdiv($ceiling, 2) && $delay <= $ceiling;
}
check($ok, "backoff within bounds");

// Parsing
$store = Store::parse(["account" => ["id" => 7, "domain" => "https://shop.tebex.io", "name" => "Shop", "currency" => ["iso_4217" => "USD"], "game_type" => Store::BEDROCK, "online_mode" => true], "server" => ["id" => 9, "name" => "Hub"]]);
check($store->scope() === "7:9" && $store->matchesByXuid() === true && $store->currency === "USD", "store parse");
check(throws(fn() => Store::parse(["account" => ["id" => "7"]]), TebexException::class), "malformed store");
check(Store::parse(["account" => ["id" => 1, "domain" => "d", "game_type" => Store::OFFLINE], "server" => ["id" => 2, "name" => "n"]])->matchesByXuid() === false, "offline store matches names");
check(Store::parse(["account" => ["id" => 1, "domain" => "d", "game_type" => "Minecraft: Java Edition"], "server" => ["id" => 2, "name" => "n"]])->matchesByXuid() === null, "java store unsupported");

$offline = QueuedCommand::parse(["id" => 5, "command" => "say hi", "payment" => 3, "package" => 4, "conditions" => ["delay" => 60], "player" => ["id" => 1, "name" => "Notch", "uuid" => "123"]]);
check($offline !== null && !$offline->online && $offline->delay === 60 && $offline->playerUuid === "123", "offline command parse");
$online = QueuedCommand::parse(["id" => 6, "command" => "give {name} tnt", "conditions" => ["delay" => 0, "slots" => 2]], ["id" => 1, "name" => "Notch", "uuid" => "123"]);
check($online !== null && $online->online && $online->slots === 2 && $online->payment === null, "online command parse");
check(QueuedCommand::parse(["id" => "5", "command" => "x", "player" => ["id" => 1, "name" => "a"]]) === null, "string id rejected");
check(QueuedCommand::parse(["id" => 0, "command" => "x", "player" => ["id" => 1, "name" => "a"]]) === null, "zero id rejected");
check(QueuedCommand::parse(["id" => 5, "command" => "x"]) === null, "offline command without player rejected");
check(QueuedCommand::parse(["id" => 5, "command" => "x", "conditions" => ["delay" => -5], "player" => ["id" => 1, "name" => "a", "uuid" => null]])?->delay === 0, "negative delay clamped");

// Settings
putenv("TEBEX_SECRET=");
putenv("TEBEX_API_URL=");
$settings = Settings::parse(["secret" => " abc "], "/data/");
check($settings->secret === "abc" && $settings->database === "/data/journal.sqlite" && $settings->apiUrl === TebexApi::BASE_URL && !$settings->allowHttp, "settings defaults");
putenv("TEBEX_SECRET=fromenv");
check(Settings::parse(["secret" => "abc"], "/data/")->secret === "fromenv", "secret from environment");
putenv("TEBEX_SECRET=");
check(throws(fn() => Settings::parse(["commands-per-tick" => 0], "/d/"), InvalidArgumentException::class), "per tick bounds");
check(throws(fn() => Settings::parse(["sender-permissions" => "x"], "/d/"), InvalidArgumentException::class), "permissions must be a list");
putenv("TEBEX_API_URL=http://evil.example");
check(throws(fn() => Settings::parse([], "/d/"), InvalidArgumentException::class), "remote http refused");
putenv("TEBEX_API_URL=http://127.0.0.1:8080/");
$local = Settings::parse([], "/d/");
check($local->allowHttp && $local->apiUrl === "http://127.0.0.1:8080", "loopback http allowed");
putenv("TEBEX_API_URL=");

// Journal
$path = sys_get_temp_dir() . "/tebex-test-" . getmypid() . ".sqlite";
foreach([$path, "$path-wal", "$path-shm"] as $file){
	@unlink($file);
}
$db = JournalTask::connect($path);
$run = fn(string $owner, array ...$ops) : array => JournalTask::apply($db, "1:1", $owner, array_values($ops));

[$load] = $run("A", ["load"]);
check($load === [], "empty journal");
[[$claimed, $others]] = $run("A", ["claim", [[1, "c1"], [2, "c2"]]]);
check($claimed === [1, 2] && $others === [], "first claim");
[[$claimed, $others]] = $run("B", ["claim", [[1, "c1"], [3, "c3"]]]);
check($claimed === [3] && $others === [1 => Journal::EXECUTING], "second claim only gets new commands");
$run("A", ["put", [[1, Journal::EXECUTED, null]]]);
[[$claimed, $others]] = $run("A", ["claim", [[1, "c1"]]]);
check($claimed === [] && $others === [1 => Journal::EXECUTED], "executed command is never claimed again");
$run("A", ["release", [2]]);
[[$claimed]] = $run("A", ["claim", [[2, "c2"]]]);
check($claimed === [2], "released command can be claimed");
check($run("B", ["release", [2]])[0] === 0, "only the owner releases");

// A restart of A quarantines what A was running, B's commands are left alone.
[$rows] = $run("A", ["load"]);
$states = array_column($rows, 1, 0);
check($states === [1 => Journal::EXECUTED, 2 => Journal::QUARANTINED, 3 => Journal::EXECUTING], "restart quarantines interrupted commands");
[$list] = $run("A", ["list", Journal::QUARANTINED]);
check(count($list) === 1 && $list[0][0] === 2 && str_starts_with($list[0][2], "interrupted while running: c2"), "quarantine listing");
[[$claimed]] = $run("A", ["claim", [[2, "c2"]]]);
check($claimed === [], "quarantined command is not claimed");
check($run("A", ["resolve", 2, false])[0] === true, "resolve retry");
[[$claimed]] = $run("A", ["claim", [[2, "c2"]]]);
check($claimed === [2], "retried command can be claimed");
$run("A", ["put", [[2, Journal::QUARANTINED, "x"]]]);
check($run("A", ["resolve", 2, true])[0] === true && $run("A", ["resolve", 2, true])[0] === false, "resolve delivered once");

[$due] = $run("A", ["defer", [[10, 1000], [11, 2000]]]);
[$again] = $run("A", ["defer", [[10, 5000]]]);
check($due === [10 => 1000, 11 => 2000] && $again === [10 => 1000], "delay keeps the first due time");
[[$claimed]] = $run("B", ["claim", [[10, "c10"]]]);
check($claimed === [10], "pending delayed command can be claimed");

$run("A", ["put", [[1, Journal::ACKED, null]]]);
$db->exec("UPDATE commands SET updated = 0 WHERE id = 1");
[$rows] = $run("A", ["load"]);
check(!in_array(1, array_column($rows, 0), true) && (int) $db->query("SELECT COUNT(*) FROM commands WHERE id = 1")->fetchColumn() === 0, "old acknowledgements are pruned");
check(throws(fn() => $run("A", ["claim", [[20, "x"]]], ["bogus"]), InvalidArgumentException::class), "unknown op throws");
check((int) $db->query("SELECT COUNT(*) FROM commands WHERE id = 20")->fetchColumn() === 0, "failed batch is rolled back");

$start = hrtime(true);
$batch = [];
for($i = 1000; $i < 6000; $i++){
	$batch[] = [$i, "say $i"];
}
[[$claimed]] = $run("A", ["claim", $batch]);
$marks = [];
foreach($claimed as $id){
	$marks[] = [$id, Journal::EXECUTED, null];
}
$run("A", ["put", $marks]);
$ms = (hrtime(true) - $start) / 1e6;
check(count($claimed) === 5000, "bulk claim");
echo sprintf("journal: claimed and recorded 5000 commands in %.1f ms\n", $ms);

$db = null;
foreach([$path, "$path-wal", "$path-shm"] as $file){
	@unlink($file);
}

echo $failed === 0 ? "OK $count checks\n" : "$failed of $count checks failed\n";
exit($failed === 0 ? 0 : 1);
