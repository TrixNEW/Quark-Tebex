<?php

declare(strict_types=1);

if (count($argv) !== 6 || !in_array($argv[5], ["quark", "pocketmine"], true)) {
    fwrite(STDERR, "Usage: php compare.php <name> <server php> <server phar> <plugin phar> <quark|pocketmine>\n");
    exit(1);
}

[, $name, $serverPhp, $serverPhar, $pluginPhar, $flavour] = $argv;
const PORT = 18601;
const SECRET = "bench-secret";
$root = dirname(__DIR__, 2);
$work = (getenv("BENCH_DIR") ?: sys_get_temp_dir()) . "/tebex-bench-" . bin2hex(random_bytes(6));
if (!mkdir("$work/plugins", 0777, true) || !copy($serverPhar, "$work/server.phar")) {
    throw new RuntimeException("Could not prepare benchmark directory $work");
}

final class Process {
    /** @var resource */
    private $process;
    /** @var array<int, resource> */
    private array $pipes;
    public string $output = "";

    public function __construct(array $command, array $env, string $cwd) {
        // The Quark PHP wrapper exports its php.ini, which must not reach another server's binary.
        $inherited = array_diff_key(getenv(), array_flip(["PHPRC", "QUARK_PHP_EXTENSION_DIR", "OPENSSL_CONF", "OPENSSL_MODULES"]));
        $this->process = proc_open($command, [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["redirect", 1]], $pipes, $cwd, $env + $inherited) ?: throw new RuntimeException("start failed");
        $this->pipes = $pipes;
        stream_set_blocking($pipes[1], false);
    }

    public function pid(): int {
        return proc_get_status($this->process)["pid"];
    }

    public function running(): bool {
        return proc_get_status($this->process)["running"];
    }

    public function pump(): void {
        while (($chunk = fread($this->pipes[1], 65536)) !== false && $chunk !== "") {
            $this->output .= $chunk;
        }
    }
    public function send(string $line): void {
        fwrite($this->pipes[0], $line . "\n");
    }

    public function stop(): void {
        if (!$this->running()) return;

        $this->send("stop");
        $until = microtime(true) + 60;
        while ($this->running() && microtime(true) < $until) {
            $this->pump();
            usleep(50_000);
        }
        if ($this->running()) {
            $this->kill();
        }
    }

    public function kill(): void {
        proc_terminate($this->process, 9);
        while ($this->running()) {
            usleep(20_000);
        }
        $this->pump();
    }
}

function state(Closure $change): void {
    global $work;
    $lock = fopen("$work/fake.json.lock", "c");
    flock($lock, LOCK_EX);
    file_put_contents("$work/fake.json", json_encode($change(json_decode((string) file_get_contents("$work/fake.json"), true))));
    flock($lock, LOCK_UN);
}

function acked(): array {
    global $work;
    return json_decode((string) file_get_contents("$work/fake.json"), true)["acked"];
}

function requests(): int {
    global $work;
    return count(file("$work/fake.json.log", FILE_SKIP_EMPTY_LINES) ?: []);
}

function offline(int $id, string $command): array {
    return [
        "id" => $id,
        "command" => $command,
        "payment" => $id,
        "package" => 1,
        "conditions" => ["delay" => 0],
        "player" => ["id" => 1, "name" => "Notch", "uuid" => "2535400000000001"],
    ];
}

/** CPU seconds of the whole process and of its main thread, and resident memory in MB. */
function usage(int $pid): array {
    $ticks = static fn(string $stat): float => ((int) explode(" ", $stat)[13] + (int) explode(" ", $stat)[14]) / 100;
    preg_match('/VmRSS:\s+(\d+)/', (string) file_get_contents("/proc/$pid/status"), $rss);
    return [$ticks((string) file_get_contents("/proc/$pid/stat")), $ticks((string) file_get_contents("/proc/$pid/task/$pid/stat")), (int) $rss[1] / 1024];
}

/** Everything the servers logged so far. Server output is read from the logs because stdout reaches a pipe late. */
function serverLog(): string {
    global $work;
    $text = "";
    foreach (array_merge(glob("$work/logs/*") ?: [], glob("$work/log_archive/*") ?: [], ["$work/server.log"]) as $file) {
        if (is_file($file)) {
            $data = (string) file_get_contents($file);
            $text .= str_ends_with($file, ".gz") ? (string) gzdecode($data) : $data;
        }
    }
    return $text;
}

function startServer(bool $plugin): Process {
    global $serverPhp, $work, $pluginPhar;
    @unlink("$work/plugins/Tebex.phar");
    if ($plugin) {
        copy($pluginPhar, "$work/plugins/Tebex.phar");
    }
    return new Process(
        [$serverPhp, "-d", "opcache.jit=off", "server.phar", "--no-wizard", "--disable-ansi", "--data=$work", "--plugins=$work/plugins"],
        ["TEBEX_API_URL" => "http://127.0.0.1:" . PORT, "TEBEX_SECRET" => SECRET],
        $work
    );
}

function ready(Process $server): bool {
    $before = substr_count(serverLog(), "Done (");
    $until = microtime(true) + 120;
    while (substr_count(serverLog(), "Done (") === $before && microtime(true) < $until && $server->running()) {
        $server->pump();
        usleep(50_000);
    }
    return substr_count(serverLog(), "Done (") > $before;
}

function executed(string $prefix): int {
    return preg_match_all('/\] ' . $prefix . '\d+$/m', serverLog());
}

/** Seconds between the first and last logged run of the commands. */
function runSpan(string $prefix): float {
    preg_match_all('/^(\d{4}-\d\d-\d\d)[T ]\[?(\d\d:\d\d:\d\d(?:\.\d+)?).*\] ' . $prefix . '\d+$/m', serverLog(), $m);
    if ($m[0] === []) {
        return 0.0;
    }
    $at = static fn(int $i): float => (float) strtotime($m[1][$i] . " " . substr($m[2][$i], 0, 8)) + (float) ("0" . (strstr($m[2][$i], ".") ?: ""));
    return $at(count($m[0]) - 1) - $at(0);
}

/** Waits while draining the server's stdout: PocketMine writes the console on the main thread and stalls on a full pipe. */
function waitUntil(Closure $condition, float $timeout): bool {
    global $server;
    $until = microtime(true) + $timeout;
    while (!$condition()) {
        if (microtime(true) > $until) return false;
        $server?->pump();
        usleep(50_000);
    }
    return true;
}

function idle(float $seconds): void {
    waitUntil(fn(): bool => false, $seconds);
}

function result(string $key, string $value): void {
    global $name;
    echo str_pad("$name $key", 44) . $value . "\n";
}

file_put_contents("$work/fake.json", json_encode([
    "secret" => SECRET, "game_type" => "Minecraft (Bedrock)", "next_check" => 60, "players" => [], "online" => [], "offline" => [],
    "offline_limit" => 5000, "sticky" => false, "acked" => [], "fail" => [], "limit" => [], "malformed" => [], "retry_after" => 1,
]));
touch("$work/fake.json.log");
$api = new Process([PHP_BINARY, "$root/tests/integration/fake-tebex.php", (string) PORT, "$work/fake.json"], [], $work);
register_shutdown_function(static function () use ($api, $work): void {
    global $server;
    $server?->stop();
    $api->kill();
    echo "Benchmark logs: $work\n";
});

// A development PHAR can exit after writing its default configuration.
$server = startServer(false);
ready($server);
$server->stop();
if ($flavour === "quark") {
    $config = "$work/config/server.toml";
    if (!is_file($config)) {
        throw new RuntimeException("Quark failed to write its configuration: " . substr($server->output, -2000));
    }
    $toml = preg_replace(
        ['/^enable-dev-builds = false/m', '/^port = 19132/m', '/^port-v6 = 19133/m'],
        ["enable-dev-builds = true", "port = 29601", "port-v6 = 29602"],
        (string) file_get_contents($config)
    );
    file_put_contents($config, $toml);
} else{
    $props = preg_replace(['/^server-port=.*/m', '/^server-portv6=.*/m'], ["server-port=29601", "server-portv6=29602"], (string) file_get_contents("$work/server.properties"));
    file_put_contents("$work/server.properties", $props);
    @mkdir("$work/plugin_data/Tebex", 0777, true);
    file_put_contents("$work/plugin_data/Tebex/config.yml", "secret: \"" . SECRET . "\"\nworker-limit: 2\ndisabled-sub-commands: []\npermissions: []\n");
}
$server = startServer(false);
if (!ready($server)) {
    throw new RuntimeException("Benchmark server did not start: " . substr($server->output, -2000));
}
$server->stop();

// Idle: the same server with and without the plugin, 60s each.
foreach (getenv("BENCH_SKIP_IDLE") ? [true] : [false, true] as $plugin) {
    $started = microtime(true);
    $server = startServer($plugin);
    if (!ready($server)) {
        throw new RuntimeException("Benchmark server did not start: " . substr($server->output, -2000));
    }
    if ($plugin) {
        result("enable to ready", sprintf("%.2fs", microtime(true) - $started));
    }
    idle(5);
    $before = requests();
    [$cpu0, $main0] = usage($server->pid());
    idle(60);
    [$cpu1, $main1, $rss] = usage($server->pid());
    result($plugin ? "idle with plugin" : "idle without plugin", sprintf("%.2fs CPU (main %.2fs) / 60s, %.0f MB, %d requests", $cpu1 - $cpu0, $main1 - $main0, $rss, requests() - $before));
    if ($plugin) {
        $idleServer = $server;
    } else{
        $server->stop();
    }
}
$server = $idleServer;

// Throughput: 2000 offline commands at once.
$bulk = [];
for ($i = 1; $i <= 2000; $i++) {
    $bulk[] = offline(100000 + $i, "say BULK$i");
}
state(function (array $s) use ($bulk): array {
    $s["offline"] = $bulk;
    return $s;
});
$before = requests();
[$cpu0, $main0] = usage($server->pid());
$start = microtime(true);
$server->send("tebex refresh");
$ran = waitUntil(fn(): bool => executed("BULK") >= 2000, 300) ? microtime(true) - $start : null;
$acked = waitUntil(fn(): bool => count(array_intersect(range(100001, 102000), acked())) === 2000, 300) ? microtime(true) - $start : null;
if (!$server->running()) {
    $server->pump();
    echo "server died:
" . substr($server->output, -3000) . "
";
    exit(1);
}
[$cpu1, $main1] = usage($server->pid());
result("2000 commands ran", $ran === null ? "timed out (" . executed("BULK") . " runs)" : sprintf("%.2fs (runs spread over %.2fs), %d runs", $ran, runSpan("BULK"), executed("BULK")));
result("2000 commands acknowledged", $acked === null ? "timed out" : sprintf("%.2fs", $acked));
result("bulk CPU total / main thread", sprintf("%.2fs / %.2fs", $cpu1 - $cpu0, $main1 - $main0));
result("bulk requests", (string) (requests() - $before));

// Duplicates: Tebex keeps returning the commands and the first acknowledgements fail.
state(function (array $s): array {
    $s["offline"] = [];
    for ($i = 1; $i <= 20; $i++) {
        $s["offline"][] = offline(200000 + $i, "say DUP$i");
    }
    $s["sticky"] = true;
    $s["fail"] = ["DELETE /queue" => 3];
    return $s;
});
for ($round = 0; $round < 3; $round++) {
    $server->send("tebex refresh");
    idle(11);
}
result("20 commands, redelivered + failed acks", executed("DUP") . " runs");

// Crash: commands run, acknowledgements fail, the server is killed and restarted.
state(function (array $s): array {
    $s["offline"] = [];
    for ($i = 1; $i <= 20; $i++) {
        $s["offline"][] = offline(300000 + $i, "say CRASH$i");
    }
    $s["sticky"] = false;
    $s["fail"] = ["DELETE /queue" => 100000];
    return $s;
});
idle(11);
$server->send("tebex refresh");
waitUntil(fn(): bool => executed("CRASH") >= 20, 30);
idle(0.5);
$server->kill();
state(function (array $s): array {
    $s["fail"] = [];
    return $s;
});
$server = startServer(true);
if (!ready($server)) {
    echo "restart failed:\n" . substr($server->output, -2000) . "\n";
}
idle(5);
$server->send("tebex refresh");
idle(15);
result("20 commands, killed before acknowledgement", executed("CRASH") . " runs");
$server->stop();
