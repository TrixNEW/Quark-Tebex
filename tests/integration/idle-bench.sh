#!/bin/bash
set -euo pipefail

if (( $# != 2 )); then
    echo "Usage: idle-bench.sh <php> <Quark-v6.phar>" >&2
    exit 1
fi

php=$(realpath "$1")
server=$(realpath "$2")
root=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d)
echo "Benchmark logs: $work"
mkdir "$work/plugins"
cd "$work"

server_args=("$server" --no-wizard --disable-ansi "--data=$work" "--plugins=$work/plugins")
"$php" "${server_args[@]}" <<< stop > setup.log 2>&1 || true
sed -i 's/^enable-dev-builds = false/enable-dev-builds = true/; s/^port = 19132/port = 29501/; s/^port-v6 = 19133/port-v6 = 29502/' config/server.toml
"$php" "${server_args[@]}" <<< stop >> setup.log 2>&1
echo '{"secret":"s","game_type":"Minecraft (Bedrock)","next_check":10,"players":[],"offline":[],"acked":[]}' > fake.json
"$php" "$root/tests/integration/fake-tebex.php" 18501 "$work/fake.json" > api.log 2>&1 &
api_pid=$!
server_pid=
trap 'kill "$api_pid" ${server_pid:+"$server_pid"} 2>/dev/null || true' EXIT

measure() {
    local label=$1 before after input
    mkfifo "in-$label"
    exec {input}<>"in-$label"
    TEBEX_SECRET=s TEBEX_API_URL=http://127.0.0.1:18501 "$php" "${server_args[@]}" \
        <&"$input" > "out-$label.log" 2>&1 &
    server_pid=$!

    sleep 25
    before=$(awk '{print $14+$15}' "/proc/$server_pid/stat")
    sleep 60
    after=$(awk '{print $14+$15}' "/proc/$server_pid/stat")
    printf '%s: %d CPU ticks/60s, %s kB RSS, %s threads\n' \
        "$label" "$((after - before))" \
        "$(awk '/VmRSS/{print $2}' "/proc/$server_pid/status")" \
        "$(ls "/proc/$server_pid/task" | wc -l)"

    echo stop >&"$input"
    exec {input}>&-
    wait "$server_pid"
    server_pid=
}

"$php" -d phar.readonly=0 "$root/build.php" >/dev/null
measure without1
cp "$root/build/Tebex_1.0.0.phar" plugins/Tebex.phar
measure with
rm plugins/Tebex.phar
measure without2
echo "Queue polls: $(grep -c '/queue"' fake.json.log)"
