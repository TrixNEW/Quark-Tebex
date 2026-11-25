#!/bin/bash
# Idle CPU and memory of a Quark server with and without the plugin: idle-bench.sh <php> <Quark-v6.phar>
# Runs without, with, without, so world generation on the first boot does not count against either.
P=$1
R=$(cd "$(dirname "$0")/../.." && pwd)
W=/tmp/tbench; rm -rf $W; mkdir -p $W/plugins; cd $W
cp "$2" Quark-v6.phar
(sleep 20; echo stop) | $P Quark-v6.phar --no-wizard --disable-ansi --data=$W --plugins=$W/plugins >/dev/null 2>&1
sed -i 's/^enable-dev-builds = false/enable-dev-builds = true/; s/^port = 19132/port = 29501/; s/^port-v6 = 19133/port-v6 = 29502/' config/server.toml
echo '{"secret":"s","game_type":"Minecraft (Bedrock)","next_check":10,"players":[],"online":[],"offline":[],"offline_limit":250,"acked":[],"fail":[],"limit":[],"malformed":[],"retry_after":1}' > fake.json
$P $R/tests/integration/fake-tebex.php 18501 $W/fake.json >/dev/null 2>&1 &
FAKE=$!
measure() {
  mkfifo in$1; (sleep 100; echo stop) > in$1 &
  TEBEX_SECRET=s TEBEX_API_URL=http://127.0.0.1:18501 $P Quark-v6.phar --no-wizard --disable-ansi --data=$W --plugins=$W/plugins < in$1 > out$1.log 2>&1 &
  PID=$!
  sleep 25
  T0=$(awk '{print $14+$15}' /proc/$PID/stat); sleep 60; T1=$(awk '{print $14+$15}' /proc/$PID/stat)
  echo "$1: cpu $(( T1 - T0 )) ticks/60s (100Hz), rss $(awk '/VmRSS/{print $2}' /proc/$PID/status) kB, threads $(ls /proc/$PID/task | wc -l), connected=$(grep -c 'Connected to' out$1.log)"
  wait $PID
}
(cd $R && $P -d phar.readonly=0 build.php >/dev/null)
measure without1
cp $R/build/Tebex_1.0.0.phar plugins/Tebex.phar
sleep 5
measure with
rm plugins/Tebex.phar
sleep 5
measure without2
kill $FAKE
echo "queue polls during run: $(grep -c '/queue"' fake.json.log)"
