# Tebex

Lightweight, asynchronous Tebex integration for Quark v6.

## Features

- Asynchronous HTTP requests and SQLite journaling.
- Persistent command queue with duplicate execution protection.
- Batched acknowledgements and automatic retries.
- Rate limiting, connection reuse, and request backoff.
- Tick-budgeted command execution.
- Support for offline purchases, refunds, renewals, expirations, and chargebacks.
- XUID-based player identification.

## Requirements

- Quark v6
- PHP 8.4
- `curl`, `pdo_sqlite`, `igbinary`

## Installation

1. Place `Tebex.phar` in the `plugins` directory.
2. Start the server and configure `config.toml` with your Tebex secret.
3. Restart and run `/tebex info` to verify the connection.

Use a Minecraft (Bedrock) webstore with Xbox authentication enabled. Offline/Geyser webstores require verified usernames.

## Configuration

```toml
secret = ""
buy-message = "Support the server at {url}"
commands-per-tick = 50
sender-permissions = ["quark.group.operator"]
database = "journal.sqlite"
```

The `TEBEX_SECRET` environment variable overrides `secret`. Command execution uses a 5 ms per-tick budget.
Set Quark's `[logging]` console or file level to `debug` in `config/server.toml` for detailed Tebex diagnostics.

## Commands

| Command | Permission | Description |
|---|---|---|
| `/buy`, `/store` | `tebex.buy` | Display the webstore URL |
| `/tebex info` | `tebex.admin` | Display store information |
| `/tebex status` | `tebex.admin` | Display queue status |
| `/tebex refresh` | `tebex.admin` | Request a queue refresh |
| `/tebex quarantine` | `tebex.admin` | List quarantined commands |
| `/tebex resolve <id> <delivered\|retry>` | `tebex.admin` | Resolve a quarantined command |

## Benchmarks

Comparison against Muqsit/Tebex on Axolotl using the same test scenarios.

| Benchmark | Muqsit / Axolotl | Tebex / Quark v6 |
|---|---:|---:|
| Idle CPU (60s) | 0.62s | **0.13s** |
| Idle CPU without plugin | 0.64s | 0.12s |
| Memory overhead | +6 MB | **+3 MB** |
| 2,000 commands: executions | 3,000 | **2,000** |
| 2,000 commands: acknowledgement time | 62.8s | **2.27s** |
| 2,000 commands: main-thread CPU | 0.40s | **0.09s** |
| 2,000 commands: total CPU | 1.16s | **0.20s** |
| 20 commands: failed acknowledgements | 60 executions | **20 executions** |
| 20 commands: crash before acknowledgement | 20 executions | 20 executions |

- **27.7x** faster acknowledgement.
- **82.8%** lower total CPU usage during command processing.
- **77.5%** lower main-thread CPU usage.
- **50%** lower memory overhead.
- No duplicate executions observed.

Results compare complete plugin and server combinations, not isolated plugin performance.

## Reliability

Commands are durably journaled before execution and acknowledged only after execution completes. Failed acknowledgements are retried without replaying completed commands.

Within a fetched purchase, later commands can wait for another queue check when an earlier command is delayed or refused.

Commands with uncertain execution states are quarantined for manual resolution. Use the command IDs from `/tebex quarantine` to investigate the original purchases before resolving them.

Each server should use its own Tebex secret. Servers sharing a secret on the same host must share a journal database.

## Developer API

Provides a rate-limited asynchronous Tebex API client through `Tebex::getApi()` and the following events:

- `TebexCommandExecuteEvent`
- `TebexCommandExecutedEvent`
- `TebexCommandQuarantinedEvent`

## Development

Use Quark's PHP runtime for the tests and PHPStan.

```sh
composer install
php tests/run.php
php vendor/bin/phpstan analyse
php tests/integration/run.php <Quark-v6.phar>
composer build
```

## Sources

Inspired by [Muqsit/Tebex](https://github.com/Muqsit/Tebex). No original source code is reused.
**MIT** License
