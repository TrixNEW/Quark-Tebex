<?php

declare(strict_types=1);

namespace Trix\Tebex;

use quark\event\Listener;
use quark\event\player\PlayerJoinEvent;
use quark\event\player\PlayerQuitEvent;
use quark\plugin\DisablePluginException;
use quark\plugin\PluginBase;
use quark\scheduler\AsyncPool;
use quark\scheduler\ClosureTask;
use quark\scheduler\TaskHandler;
use quark\Server;
use quark\utils\Filesystem;
use Trix\Tebex\api\TebexApi;
use Trix\Tebex\api\TebexException;
use Trix\Tebex\command\BuyCommand;
use Trix\Tebex\command\TebexCommand;
use Trix\Tebex\journal\Journal;
use Vow\Promise;
use function count;
use function dirname;

final class Tebex extends PluginBase implements Listener {
    // HTTP requests must never block journal writes.
    private const int JOURNAL_WORKER = 0;
    private const array HTTP_WORKERS = [1, 2];

    private Settings $settings;
    private ?AsyncPool $pool = null;
    private ?TebexApi $api = null;
    private ?CommandQueue $queue = null;
    private ?Journal $journal = null;
    /** @phpstan-var TaskHandler<ClosureTask>|null */
    private ?TaskHandler $retry = null;
    private bool $connecting = false;
    private int $failures = 0;

    protected function onEnable(): void {
        try {
            $config = $this->getConfig()->getAll();
        } catch (\Exception) {
            $this->getLogger()->error("Could not read the Tebex config.toml");
            throw new DisablePluginException();
        }
        try {
            $this->settings = Settings::parse($config, $this->getDataFolder());
        } catch (\InvalidArgumentException $e) {
            $this->getLogger()->error("Invalid Tebex configuration: " . $e->getMessage());
            throw new DisablePluginException();
        }

        $map = $this->getServer()->getCommandMap();
        $map->register("tebex", new TebexCommand($this));
        $map->register("tebex", new BuyCommand($this, $this->settings->buyMessage));

        if ($this->settings->secret === "") {
            $this->getLogger()->warning("No secret key set, add it to config.toml or the TEBEX_SECRET environment variable");
            return;
        }

        $server = $this->getServer();
        Filesystem::ensureDirectory(dirname($this->settings->database));
        $this->pool = new AsyncPool(
            1 + count(self::HTTP_WORKERS),
            64,
            $server->getLoader(),
            $server->getLogger(),
            $server->getTickSleeper()
        );
        $this->api = new TebexApi(
            $this->pool,
            self::HTTP_WORKERS,
            $this->getScheduler(),
            $this->settings->secret,
            $this->settings->apiUrl,
            $this->settings->allowHttp
        );
        $server->getPluginManager()->registerEvents($this, $this);
        $this->connect();
    }

    protected function onDisable(): void {
        $this->retry?->cancel();
        $this->retry = null;
        $this->queue?->close();
        $this->api?->close();
        $this->pool?->shutdown();
        $this->journal?->close();
        $this->queue = $this->api = $this->pool = $this->journal = null;
        $this->connecting = false;
        $this->failures = 0;
    }

    /**
     * The HTTP client for any Plugin API endpoint, null when no secret key is set.
     */
    public function getApi(): ?TebexApi {
        return $this->api;
    }

    /**
     * The connected store, null until the store information was fetched.
     */
    public function getStore(): ?Store {
        return $this->queue?->getStore();
    }

    public function getCommandQueue(): ?CommandQueue {
        return $this->queue;
    }

    /**
     * @phpstan-return Promise<Store>
     */
    public function fetchStore(): Promise {
        if ($this->api === null) {
            return Promise::rejected(new TebexException("No secret key is set"));
        }
        return $this->api->get("/information")->then(fn(mixed $data): Store => Store::parse($data));
    }

    /**
     * Connects if processing has not started yet, returns whether a connection attempt started.
     */
    public function connect(): bool {
        if ($this->queue !== null || $this->connecting || $this->api === null || !$this->isEnabled()) return false;
        $this->connecting = true;
        $this->retry?->cancel();
        $this->retry = null;
        $this->fetchStore()->then(function (Store $store): void {
            if (!$this->isEnabled() || $this->pool === null || $this->api === null) return;

            $byXuid = $store->matchesByXuid();
            if ($byXuid === null) {
                $this->connecting = false;
                $this->getLogger()->error("Unsupported webstore game type. Use a " . Store::BEDROCK . " or " . Store::OFFLINE . " webstore.");
                return;
            }
            if (!$this->getServer()->getOnlineMode()) {
                $this->getLogger()->warning($byXuid ?
                    "This webstore identifies players by XUID but xbox-auth is off, online commands only reach players signed in to Xbox Live" :
                    "This webstore identifies players by username and xbox-auth is off, anyone joining with a buyer's name receives their online commands"
                );
            }
            $server = $this->getServer();
            $this->journal = new Journal(
                $this->pool,
                self::JOURNAL_WORKER,
                $this->settings->database,
                $store->scope(),
                $server->getServerUniqueId()->toString()
            );
            $this->queue = new CommandQueue(
                $this,
                $this->api,
                $this->journal,
                $store,
                new TebexSender($server, $this->settings->senderPermissionMap()),
                $this->settings->commandsPerTick
            );
            $this->queue->start()->then(function () use ($store): void {
                if (!$this->isEnabled()) return;

                $this->connecting = false;
                $this->failures = 0;
                $this->getLogger()->info("Connected to Tebex for account #$store->accountId, server #$store->serverId");
            })->catch(function (): void {
                if (!$this->isEnabled()) return;

                $this->connecting = false;
                $this->queue?->close();
                $this->journal?->close();
                $this->queue = $this->journal = null;
                if ($this->failures === 0 || ($this->failures + 1) % 10 === 0) {
                    $this->getLogger()->error("Could not open the Tebex delivery journal. Retrying.");
                }
                $this->scheduleConnect();
            });
        }, function (\Throwable $e): void {
            if (!$this->isEnabled()) return;

            $this->connecting = false;
            if ($e instanceof TebexException && $e->isUnauthorized()) {
                $this->getLogger()->error("Tebex refused the secret key");
                return;
            }
            if ($this->failures === 0 || ($this->failures + 1) % 10 === 0) {
                $detail = $e instanceof TebexException ? $e->getMessage() : "Unexpected request failure";
                $this->getLogger()->warning("Could not reach Tebex: $detail. Retrying.");
            }
            $this->scheduleConnect();
        })->catch(function (): void {
            $this->connecting = false;
            $this->getLogger()->error("Could not initialize the Tebex command queue");
        });
        return true;
    }

    private function scheduleConnect(): void {
        if (!$this->isEnabled()) return;
        $delay = TebexApi::backoff(++$this->failures, 10, 600);
        $this->retry = $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function (): void {
            $this->retry = null;
            $this->connect();
        }), $delay * Server::TARGET_TICKS_PER_SECOND);
    }

    /**
     * @priority MONITOR
     */
    public function onJoin(PlayerJoinEvent $event): void {
        $this->queue?->onJoin($event->getPlayer());
    }

    /**
     * @priority MONITOR
     */
    public function onQuit(PlayerQuitEvent $event): void {
        $this->queue?->onQuit($event->getPlayer());
    }
}
