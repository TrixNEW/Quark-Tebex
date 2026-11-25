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

final class Tebex extends PluginBase implements Listener{
	// Worker 0 writes the journal, the others send requests, so a slow request never holds up a write.
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

	protected function onEnable() : void{
		try{
			$this->settings = Settings::parse($this->getConfig()->getAll(), $this->getDataFolder());
		}catch(\InvalidArgumentException $e){
			$this->getLogger()->error("Invalid config.toml: " . $e->getMessage());
			throw new DisablePluginException();
		}

		$map = $this->getServer()->getCommandMap();
		$map->register("tebex", new TebexCommand($this));
		$map->register("tebex", new BuyCommand($this, $this->settings->buyMessage));

		if($this->settings->secret === ""){
			$this->getLogger()->warning("No secret key set, add it to config.toml or the TEBEX_SECRET environment variable");
			return;
		}

		$server = $this->getServer();
		Filesystem::ensureDirectory(dirname($this->settings->database));
		$this->pool = new AsyncPool(1 + count(self::HTTP_WORKERS), 64, $server->getLoader(), $server->getLogger(), $server->getTickSleeper());
		$this->api = new TebexApi($this->pool, self::HTTP_WORKERS, $this->getScheduler(), $this->settings->secret, $this->settings->apiUrl, $this->settings->allowHttp);
		$server->getPluginManager()->registerEvents($this, $this);
		$this->connect();
	}

	protected function onDisable() : void{
		$this->retry?->cancel();
		$this->queue?->close();
		$this->api?->close();
		$this->pool?->shutdown();
		// Writes made while the pool shut down were completed by it, anything later has no worker to run on.
		$this->journal?->close();
		$this->queue = $this->api = $this->pool = $this->journal = null;
	}

	/**
	 * The HTTP client for any Plugin API endpoint, null when no secret key is set.
	 */
	public function getApi() : ?TebexApi{
		return $this->api;
	}

	/**
	 * The connected store, null until the store information was fetched.
	 */
	public function getStore() : ?Store{
		return $this->queue?->getStore();
	}

	public function getCommandQueue() : ?CommandQueue{
		return $this->queue;
	}

	/**
	 * Fetches the store information. Before the first connection this also starts command processing.
	 *
	 * @phpstan-return Promise<Store>
	 */
	public function fetchStore() : Promise{
		if($this->api === null){
			return Promise::rejected(new TebexException("No secret key is set"));
		}
		return $this->api->get("/information")->then(fn(mixed $data) : Store => Store::parse($data));
	}

	/**
	 * Connects if processing has not started yet, returns whether a connection attempt started.
	 */
	public function connect() : bool{
		if($this->queue !== null || $this->connecting || $this->api === null || !$this->isEnabled()){
			return false;
		}
		$this->connecting = true;
		$this->retry?->cancel();
		$this->retry = null;
		$this->fetchStore()->then(function(Store $store) : void{
			$byXuid = $store->matchesByXuid();
			if($byXuid === null){
				$this->connecting = false;
				$this->getLogger()->error("Webstore game type \"$store->gameType\" is not supported, use a \"" . Store::BEDROCK . "\" or \"" . Store::OFFLINE . "\" webstore");
				return;
			}
			if(!$this->getServer()->getOnlineMode()){
				$this->getLogger()->warning($byXuid ?
					"This webstore identifies players by XUID but xbox-auth is off, online commands only reach players signed in to Xbox Live" :
					"This webstore identifies players by username and xbox-auth is off, anyone joining with a buyer's name receives their online commands"
				);
			}
			$owner = $this->getServer()->getServerUniqueId()->toString();
			$this->journal = $journal = new Journal($this->pool ?? throw new \LogicException("Plugin is disabled"), self::JOURNAL_WORKER, $this->settings->database, $store->scope(), $owner);
			$sender = new TebexSender($this->getServer(), $this->settings->senderPermissionMap());
			$this->queue = $queue = new CommandQueue($this, $this->api ?? throw new \LogicException("Plugin is disabled"), $journal, $store, $sender, $this->settings->commandsPerTick, $this->settings->debug);
			$queue->start()->then(function() use ($store) : void{
				$this->connecting = false;
				$this->failures = 0;
				$this->getLogger()->info("Connected to $store->name ($store->domain) as server \"$store->serverName\"");
			}, function(\Throwable $e) use ($queue) : void{
				$this->connecting = false;
				$queue->close();
				if($this->queue === $queue){
					$this->queue = null;
				}
				$this->getLogger()->error("Could not open the delivery journal: " . $e->getMessage());
				$this->scheduleConnect();
			});
		}, function(\Throwable $e) : void{
			$this->connecting = false;
			if($e instanceof TebexException && $e->isUnauthorized()){
				$this->getLogger()->error("Tebex refused the secret key: {$e->getMessage()}");
				return;
			}
			$this->getLogger()->warning("Could not reach Tebex: {$e->getMessage()}");
			$this->scheduleConnect();
		})->catch(fn(\Throwable $e) => $this->getLogger()->logException($e));
		return true;
	}

	private function scheduleConnect() : void{
		if(!$this->isEnabled()){
			return;
		}
		$delay = TebexApi::backoff(++$this->failures, 10, 600);
		$this->retry = $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() : void{
			$this->retry = null;
			$this->connect();
		}), $delay * Server::TARGET_TICKS_PER_SECOND);
	}

	/**
	 * @priority MONITOR
	 */
	public function onJoin(PlayerJoinEvent $event) : void{
		$this->queue?->onJoin($event->getPlayer());
	}

	/**
	 * @priority MONITOR
	 */
	public function onQuit(PlayerQuitEvent $event) : void{
		$this->queue?->onQuit($event->getPlayer());
	}
}
