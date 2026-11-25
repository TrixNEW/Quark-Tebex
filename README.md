# Tebex for Quark

Delivers Tebex webstore purchases on Quark 6 servers. Purchases run once, are never lost, and are only acknowledged after they ran.

- HTTP requests and journal writes run on the plugin's own worker threads. Nothing blocks the main thread.
- Connections are kept alive. The plugin follows `next_check` and `Retry-After` and stays under the key's limit of 500 requests per 5 minutes.
- Offline commands keep running when nobody is online. Online commands are fetched only for due players who are on this server.
- Delays, inventory slot requirements and ordering within a purchase are respected. A delay survives restarts.
- Tebex runs expiry, renewal, refund and chargeback commands through the game server plugin. The Plugin API has a single command queue, so the plugin handles them exactly like purchase commands.

Requires PHP 8.4 and Quark 6 with `curl`, `pdo_sqlite` and `igbinary`, all part of the standard Quark binaries.

## Installation

1. Put `Tebex_<version>.phar` in the `plugins` folder.
2. Start the server once, then set `secret` in the plugin's `config.toml`, or set the `TEBEX_SECRET` environment variable. The secret key is under creator.tebex.io > Game Servers.
3. Restart the server and run `/tebex info` to check the connection.

Use a **Minecraft (Bedrock)** webstore. Players are matched by XUID, which requires `xbox-auth`. **Minecraft (Offline/Geyser)** webstores match players by username instead. Only use one where names are verified, by `xbox-auth` or an authenticating proxy, or anyone can claim a buyer's online commands by joining with their name. Java webstores are refused because their player IDs cannot match Bedrock players.

## Configuration

```toml
secret = ""                                 # TEBEX_SECRET overrides it
buy-message = "Support the server at {url}" # shown by /buy
commands-per-tick = 10                      # the rest wait for the following ticks
sender-permissions = ["quark.group.operator"]
database = "journal.sqlite"                 # relative to the plugin data folder
debug = false
```

Purchase commands run as a dedicated `Tebex` sender that has only `sender-permissions`. Commands are logged, and the secret never is.

## Commands

| Command | Permission | |
|---|---|---|
| `/buy`, `/store` | `tebex.buy` (everyone) | Shows the webstore address |
| `/tebex info` | `tebex.admin` | Fetches the store details and checks the secret key |
| `/tebex status` | `tebex.admin` | Shows the last and next queue check, errors and backlog |
| `/tebex refresh` | `tebex.admin` | Checks the queue now. A check that finished in the last 10 seconds is not repeated |
| `/tebex quarantine` | `tebex.admin` | Lists commands that need a decision |
| `/tebex resolve <id> <delivered\|retry>` | `tebex.admin` | Settles a quarantined command |

`/tbx` is an alias of `/tebex`.

## Delivery guarantees

Every command is recorded in a SQLite journal, scoped to the Tebex account and server:

1. The command is **claimed** before it runs. A command that is already claimed, executed or acknowledged is never run again, even if Tebex sends it again. Commands are claimed only about one second ahead of running them, so a crash sets aside at most that many.
2. It runs on the main thread. It is **recorded as executed** after the command returns without an error.
3. It is **acknowledged** to Tebex in batches of up to 100. A failed acknowledgement is retried with backoff. It never runs the command again.

A command that may have partly run is **quarantined**, not replayed. This covers a crash between steps 1 and 2, a command that threw an exception, and a command that was refused three times. `/tebex quarantine` lists them. `/tebex resolve <id> delivered` acknowledges one without running it. `/tebex resolve <id> retry` runs it again on the next check.

A command that "ran" only means it returned without an error. Whether the reward arrived depends on the command, so check the logs or listen to `TebexCommandExecutedEvent` for anything critical. A command that refuses the sender with Quark's standard permission or player-only reply counts as refused. A command with a custom permission message does not, so give the sender every permission its commands need.

Purchase commands are run directly through the command map. `CommandEvent` and Quark's command log do not see them, use the Tebex events instead.

Player names are checked before they are put into a command. Offline webstores accept any name at checkout, so a name that could escape its argument quarantines the command instead of running it. Names with spaces are quoted. The placeholders are `{name}`, `{player}` and `{username}` for the player name, and `{id}` and `{uuid}` for the XUID or Tebex player ID.

### Networks

Give every server its own Tebex game server and secret. Tebex then keeps one queue per server, and no two servers can see the same purchase. If several servers on one host must share a secret, point their `database` at the same file. The journal claim makes sure only one of them runs each command. Do not share a secret between hosts.

## Developer API

```php
use Trix\Tebex\Tebex;
use Trix\Tebex\event\TebexCommandExecuteEvent;

$tebex = $server->getPluginManager()->getPlugin("Tebex");
if($tebex instanceof Tebex){
	$store = $tebex->getStore(); // ?Store: name, domain, currency, game type, server
	$tebex->getApi()?->get("/payments?limit=10")->then(
		fn(mixed $payments) => var_dump($payments),
		fn(\Throwable $e) => $logger->warning($e->getMessage())
	);
}
```

`getApi()` returns the plugin's rate-limited client. `request($method, $path, $form)` and `get($path)` return a `Vow\Promise` with the decoded JSON. They reject with `TebexException`, which carries the HTTP status and `Retry-After`.

Events:

- `TebexCommandExecuteEvent` is called before a command runs. You can change the command line with `setCommandLine()`. A cancelled command stays in the Tebex queue and is offered again on the next check.
- `TebexCommandExecutedEvent` is called after a command ran. It has the command line and the replies the command sent.
- `TebexCommandQuarantinedEvent` is called when a command is set aside, with the reason. Use it to alert staff.

Each event has a `QueuedCommand`. It has the Tebex command, payment, package, conditions and player.

## Development

```sh
composer install
php tests/run.php                       # unit and journal tests
php vendor/bin/phpstan analyse          # level 9, needs a Quark checkout in ../Quark or QUARK_DIR
php tests/integration/run.php <Quark-v6.phar>  # real server against a fake Tebex API
composer build                          # build/Tebex_<version>.phar
```

Run the tests and static analysis with a Quark PHP binary, since they need `pmmpthread` and the other Quark extensions.

## Credits

Some ideas come from [Muqsit/Tebex](https://github.com/Muqsit/Tebex): the dedicated request threads, lazy online command checks on join, and batched deletions. That repository has no license, so none of its code is used here. This plugin is MIT licensed.
