<?php

declare(strict_types=1);

namespace Trix\Tebex;

use quark\player\Player;
use quark\scheduler\ClosureTask;
use quark\scheduler\TaskHandler;
use quark\Server;
use Trix\Tebex\api\TebexApi;
use Trix\Tebex\api\TebexException;
use Trix\Tebex\event\TebexCommandQuarantinedEvent;
use Trix\Tebex\journal\Journal;
use Vow\Promise;
use function array_splice;
use function count;
use function ctype_digit;
use function implode;
use function intdiv;
use function is_array;
use function is_bool;
use function is_int;
use function max;
use function mb_strcut;
use function min;
use function strcasecmp;
use function strtolower;
use function time;

/**
 * Polls the Tebex queue and moves every command through claim, execution and acknowledgement.
 *
 * A command is claimed in the journal before it runs and recorded as executed right after, so it never runs twice.
 * Commands interrupted between the two are quarantined instead of replayed. Only recorded commands are acknowledged.
 */
final class CommandQueue{
	private const int MAX_PAGES = 10;
	private const int ACK_BATCH = 100;
	private const int REFRESH_COOLDOWN = 10;
	private const int ONLINE_COOLDOWN = 10;
	private const int MAX_REJECTIONS = 3;

	private bool $closed = false;
	private bool $suspended = false;
	/** @phpstan-var Promise<int>|null */
	private ?Promise $polling = null;
	/** @phpstan-var TaskHandler<ClosureTask>|null */
	private ?TaskHandler $pollTimer = null;
	private int $nextPollAt = 0;
	private int $lastPollAt = 0;
	private int $failures = 0;
	private ?string $lastError = null;

	/** @var array<int|string, array{id: int, name: string, uuid: string}> player key => due player */
	private array $due = [];
	/** @var array<int|string, Player> player key => online player */
	private array $online = [];
	/** @var array<int, int> Tebex player id => time of the last online command fetch */
	private array $onlineFetchedAt = [];
	private bool $fetchingOffline = false;
	private bool $offlineLimited = false;

	/** @var array<int, QueuedCommand> commands between being offered and being recorded */
	private array $active = [];
	/** @var array<int, int> journal state of commands that must not run yet: executed, quarantined or run elsewhere */
	private array $states = [];
	/** @var array<int, int> */
	private array $dueAt = [];
	/** @var array<int, int> */
	private array $rejections = [];
	/** @var list<QueuedCommand> ready commands in order, claimed only as fast as they run */
	private array $toClaim = [];
	private int $claiming = 0;
	private int $claimWindow;
	/** @var \SplPriorityQueue<array{int, int}, array{QueuedCommand, int}> */
	private \SplPriorityQueue $timers;
	/** @phpstan-var TaskHandler<ClosureTask>|null */
	private ?TaskHandler $timer = null;
	private int $timerAt = 0;
	private int $sequence = 0;

	/** @var list<int> executed this tick, recorded together */
	private array $executed = [];
	/** @var array<int, true> */
	private array $acks = [];
	private bool $acking = false;
	/** @phpstan-var TaskHandler<ClosureTask>|null */
	private ?TaskHandler $ackTimer = null;
	private int $ackFailures = 0;
	private int $ackChunk = self::ACK_BATCH;

	private Executor $executor;
	private bool $byXuid;

	public function __construct(
		private Tebex $plugin,
		private TebexApi $api,
		private Journal $journal,
		private Store $store,
		TebexSender $sender,
		int $perTick,
		private bool $debug
	){
		$this->byXuid = $store->matchesByXuid() ?? throw new \InvalidArgumentException("Unsupported store type $store->gameType");
		// A crash quarantines claimed commands that did not run yet, so only about a second of them is claimed ahead.
		$this->claimWindow = $perTick * Server::TARGET_TICKS_PER_SECOND;
		$this->timers = new \SplPriorityQueue();
		$this->timers->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
		$this->executor = new Executor($plugin, $plugin->getServer()->getCommandMap(), $sender, $perTick, $this->receiver(...), $this->report(...), $this->drained(...));
	}

	public function getStore() : Store{
		return $this->store;
	}

	/**
	 * @phpstan-return Promise<null>
	 */
	public function start() : Promise{
		return $this->journal->load()->then(function(array $rows) : null{
			if($this->closed){
				return null;
			}
			$quarantined = 0;
			foreach($rows as [$id, $state, $due]){
				if($state === Journal::PENDING){
					$this->dueAt[$id] = $due;
					continue;
				}
				$this->states[$id] = $state;
				if($state === Journal::EXECUTED){
					$this->ack($id);
				}elseif($state === Journal::QUARANTINED){
					$quarantined++;
				}
			}
			if($quarantined > 0){
				$this->plugin->getLogger()->warning("$quarantined purchase command" . ($quarantined === 1 ? " needs" : "s need") . " review, see /tebex quarantine");
			}
			foreach($this->plugin->getServer()->getOnlinePlayers() as $player){
				$this->onJoin($player);
			}
			$this->poll();
			return null;
		});
	}

	public function close() : void{
		$this->closed = true;
		$this->recordExecuted();
		foreach([$this->pollTimer, $this->timer, $this->ackTimer] as $handler){
			$handler?->cancel();
		}
		$this->pollTimer = $this->timer = $this->ackTimer = null;
		$unrun = [];
		foreach($this->executor->clear() as $command){
			$unrun[] = $command->id;
		}
		foreach($this->toClaim as $command){
			unset($this->active[$command->id]);
		}
		$this->toClaim = [];
		if($unrun !== []){
			$this->journal->release($unrun)->catch($this->logFailure("Could not return unrun commands to the queue"));
		}
		if($this->acks !== [] && !$this->acking){
			$this->flushAcks();
		}
	}

	public function onJoin(Player $player) : void{
		$key = $this->playerKey($player);
		if($key === null){
			return;
		}
		$this->online[$key] = $player;
		if(isset($this->due[$key])){
			$this->fetchOnline($this->due[$key]);
		}
	}

	public function onQuit(Player $player) : void{
		$key = $this->playerKey($player);
		if($key !== null && ($this->online[$key] ?? null) === $player){
			unset($this->online[$key]);
		}
	}

	/**
	 * Checks the queue now, or joins the check that is running. Resolves with the number of due players.
	 * Rejects if a check finished moments ago, so manual refreshes cannot outpace next_check by much.
	 *
	 * @phpstan-return Promise<int>
	 */
	public function refresh() : Promise{
		if($this->closed){
			return Promise::rejected(new \RuntimeException("Tebex is shutting down"));
		}
		if($this->polling === null){
			$wait = $this->lastPollAt + self::REFRESH_COOLDOWN - time();
			if($wait > 0 && !$this->suspended){
				return Promise::rejected(new \RuntimeException("The queue was just checked, try again in {$wait}s"));
			}
			$this->suspended = false;
		}
		return $this->poll()->then(fn(int $_) : int => count($this->due));
	}

	/**
	 * @return array<string, int|string|bool|null>
	 */
	public function status() : array{
		return [
			"polling" => $this->polling !== null,
			"suspended" => $this->suspended,
			"lastPoll" => $this->lastPollAt,
			"nextPoll" => $this->nextPollAt,
			"failures" => $this->failures,
			"lastError" => $this->lastError,
			"duePlayers" => count($this->due),
			"active" => count($this->active),
			"running" => $this->executor->queued(),
			"unacknowledged" => count($this->acks),
			"requests" => $this->api->pending(),
			"journalWrites" => $this->journal->pending()
		];
	}

	/** @phpstan-return Promise<list<array{int, int, string}>> */
	public function quarantined() : Promise{
		return $this->journal->quarantined();
	}

	/**
	 * Settles a quarantined command. Delivered commands are acknowledged, the others run again on the next check.
	 *
	 * @phpstan-return Promise<bool>
	 */
	public function resolve(int $id, bool $delivered) : Promise{
		return $this->journal->resolve($id, $delivered)->then(function(bool $resolved) use ($id, $delivered) : bool{
			if($resolved){
				unset($this->rejections[$id], $this->dueAt[$id]);
				if($delivered){
					$this->states[$id] = Journal::EXECUTED;
					$this->ack($id);
				}else{
					unset($this->states[$id]);
				}
			}
			return $resolved;
		});
	}

	/**
	 * @phpstan-return Promise<int>
	 */
	private function poll() : Promise{
		if($this->polling !== null){
			return $this->polling;
		}
		$this->pollTimer?->cancel();
		$this->pollTimer = null;
		$promise = Promise::async(function() : int{
			$players = [];
			$executeOffline = false;
			$next = 60;
			for($page = 1; $page <= self::MAX_PAGES; ++$page){
				$data = $this->api->get($page === 1 ? "/queue" : "/queue?page=$page")->await();
				if(!is_array($data) || !is_array($data["meta"] ?? null) || !is_array($data["players"] ?? null)){
					throw new TebexException("Malformed queue from Tebex");
				}
				$meta = $data["meta"];
				if($page === 1){
					$next = is_int($meta["next_check"] ?? null) ? $meta["next_check"] : 60;
					$executeOffline = is_bool($meta["execute_offline"] ?? null) && $meta["execute_offline"];
				}
				$added = false;
				foreach($data["players"] as $entry){
					$player = QueuedCommand::parsePlayer($entry);
					if($player !== null && !isset($players[$player["id"]])){
						$players[$player["id"]] = $player;
						$added = true;
					}
				}
				if(($meta["more"] ?? false) !== true || !$added){
					break;
				}
			}
			$this->setDue($players);
			if($executeOffline){
				$this->fetchOffline();
			}
			return $next;
		});
		$this->polling = $promise;
		$promise->then(function(int $next) : void{
			$this->polling = null;
			$this->failures = 0;
			$this->lastError = null;
			$this->lastPollAt = time();
			$this->debug("Checked the queue, " . count($this->due) . " due players, next check in {$next}s");
			$this->schedulePoll(max(10, min(3600, $next)));
			if($this->acks !== [] && !$this->acking && $this->ackTimer === null){
				$this->flushAcks();
			}
		}, function(\Throwable $e) : void{
			$this->polling = null;
			$this->lastPollAt = time();
			$this->failures++;
			$this->lastError = $e->getMessage();
			if($e instanceof TebexException && $e->isUnauthorized()){
				$this->suspend($e);
				return;
			}
			$delay = max($e instanceof TebexException ? $e->retryAfter ?? 0 : 0, TebexApi::backoff($this->failures, 10, 600));
			if($this->failures === 1 || $this->failures % 10 === 0){
				$this->plugin->getLogger()->warning("Could not check the Tebex queue: {$e->getMessage()}, retrying in {$delay}s");
			}
			$this->schedulePoll($delay);
		})->catch($this->logFailure("Queue check failed"));
		return $promise;
	}

	private function schedulePoll(int $seconds) : void{
		$this->pollTimer?->cancel();
		$this->nextPollAt = time() + $seconds;
		$this->pollTimer = $this->later($seconds, function() : void{
			$this->pollTimer = null;
			$this->poll();
		});
	}

	private function suspend(TebexException $e) : void{
		$this->suspended = true;
		$this->pollTimer?->cancel();
		$this->pollTimer = null;
		$this->nextPollAt = 0;
		$this->plugin->getLogger()->error("Tebex refused the secret key ({$e->getMessage()}), processing is paused until /tebex refresh");
	}

	/**
	 * @param array<int, array{id: int, name: string, uuid: string}> $players
	 */
	private function setDue(array $players) : void{
		$this->due = [];
		$fetchedAt = [];
		foreach($players as $player){
			if(isset($this->onlineFetchedAt[$player["id"]])){
				$fetchedAt[$player["id"]] = $this->onlineFetchedAt[$player["id"]];
			}
			$key = $this->byXuid ? (ctype_digit($player["uuid"]) ? $player["uuid"] : null) : strtolower($player["name"]);
			if($key !== null){
				$this->due[$key] = $player;
			}
		}
		$this->onlineFetchedAt = $fetchedAt;
		// Players who left are skipped entirely, an empty server only keeps offline commands moving.
		foreach($this->online as $key => $_){
			if(isset($this->due[$key])){
				$this->fetchOnline($this->due[$key]);
			}
		}
	}

	/**
	 * @param array{id: int, name: string, uuid: string} $player
	 */
	private function fetchOnline(array $player) : void{
		$now = time();
		if($this->closed || $this->suspended || ($this->onlineFetchedAt[$player["id"]] ?? 0) > $now - self::ONLINE_COOLDOWN){
			return;
		}
		$this->onlineFetchedAt[$player["id"]] = $now;
		$this->api->get("/queue/online-commands/" . $player["id"])->then(function(mixed $data) use ($player) : void{
			$this->offer($this->parseCommands($data, $player));
		})->catch($this->logFailure("Could not fetch online commands of {$player["name"]}"));
	}

	private function fetchOffline() : void{
		if($this->closed || $this->suspended || $this->fetchingOffline){
			return;
		}
		$this->fetchingOffline = true;
		$this->api->get("/queue/offline-commands")->then(function(mixed $data) : void{
			$this->fetchingOffline = false;
			$meta = is_array($data) ? $data["meta"] ?? null : null;
			$this->offlineLimited = is_array($meta) && ($meta["limited"] ?? false) === true;
			$this->offer($this->parseCommands($data, null));
		}, function(\Throwable $e) : void{
			$this->fetchingOffline = false;
			throw $e;
		})->catch($this->logFailure("Could not fetch offline commands"));
	}

	/**
	 * @param array{id: int, name: string, uuid: string}|null $player
	 * @return list<QueuedCommand>
	 */
	private function parseCommands(mixed $data, ?array $player) : array{
		$entries = is_array($data) ? $data["commands"] ?? null : null;
		if(!is_array($entries)){
			throw new TebexException("Malformed command list from Tebex");
		}
		$commands = [];
		foreach($entries as $entry){
			$command = QueuedCommand::parse($entry, $player);
			if($command === null){
				$this->plugin->getLogger()->warning("Skipped a malformed command from Tebex");
				continue;
			}
			$commands[] = $command;
		}
		return $commands;
	}

	/**
	 * @param list<QueuedCommand> $commands
	 */
	private function offer(array $commands) : void{
		if($this->closed){
			return;
		}
		$deferred = [];
		$blocked = [];
		foreach($commands as $command){
			$id = $command->id;
			if(isset($this->active[$id])){
				continue;
			}
			$state = $this->states[$id] ?? null;
			if($state === Journal::EXECUTED || $state === Journal::ACKED){
				$this->ack($id);
				continue;
			}
			if($state !== null){
				continue;
			}
			$this->active[$id] = $command;
			if($command->delay === 0){
				$this->ready($command, $blocked);
			}elseif(isset($this->dueAt[$id])){
				$this->schedule($command, $this->dueAt[$id], $blocked);
			}else{
				$deferred[] = $command;
			}
		}
		$this->flushClaims();
		if($deferred === []){
			return;
		}
		// Due times are whole seconds, rounded up so a delay never runs short.
		$now = time() + 1;
		$entries = [];
		foreach($deferred as $command){
			$entries[] = [$command->id, $now + $command->delay];
		}
		$this->journal->defer($entries)->then(function(array $due) use ($deferred, $now) : void{
			$blocked = [];
			foreach($deferred as $command){
				if(($this->active[$command->id] ?? null) === $command){
					$this->schedule($command, $this->dueAt[$command->id] = $due[$command->id] ?? $now + $command->delay, $blocked);
				}
			}
			$this->flushClaims();
		}, function(\Throwable $e) use ($deferred) : void{
			foreach($deferred as $command){
				unset($this->active[$command->id]);
			}
			throw $e;
		})->catch($this->logFailure("Could not record delayed commands"));
	}

	/**
	 * @param array<int, true> $blocked payments with a command that is waiting
	 */
	private function schedule(QueuedCommand $command, int $at, array &$blocked) : void{
		if($at <= time()){
			$this->ready($command, $blocked);
			return;
		}
		$this->timers->insert([$command, $at], [-$at, -++$this->sequence]);
		if($this->timer === null || $at < $this->timerAt){
			$this->armTimer();
		}
	}

	private function armTimer() : void{
		$this->timer?->cancel();
		$this->timer = null;
		if(!$this->timers->isEmpty()){
			/** @var array{QueuedCommand, int} $next */
			$next = $this->timers->top();
			$this->timerAt = $next[1];
			$this->timer = $this->later($this->timerAt - time(), $this->fireTimers(...));
		}
	}

	private function fireTimers() : void{
		$this->timer = null;
		$now = time();
		$blocked = [];
		while(!$this->timers->isEmpty()){
			/** @var array{QueuedCommand, int} $entry */
			$entry = $this->timers->top();
			if($entry[1] > $now){
				break;
			}
			$this->timers->extract();
			$command = $entry[0];
			if(($this->active[$command->id] ?? null) === $command){
				$this->ready($command, $blocked);
			}
		}
		$this->flushClaims();
		$this->armTimer();
	}

	/**
	 * @param array<int, true> $blocked
	 */
	private function ready(QueuedCommand $command, array &$blocked) : void{
		$player = $command->online ? $this->receiver($command) : null;
		$reason = $command->payment !== null && isset($blocked[$command->payment]) ? "an earlier command of the purchase is waiting" : $this->executor->blocker($command, $player);
		if($reason !== null){
			unset($this->active[$command->id]);
			if($command->payment !== null){
				$blocked[$command->payment] = true;
			}
			$this->debug("Command #$command->id waits: $reason");
			if($command->slots > 0 && $player !== null){
				$player->sendMessage("Free up $command->slots inventory slots to receive your purchase.");
			}
			return;
		}
		$this->toClaim[] = $command;
	}

	private function flushClaims() : void{
		$room = $this->claimWindow - $this->claiming - $this->executor->queued();
		if($this->toClaim === [] || $room <= 0 || $this->closed){
			return;
		}
		$batch = [];
		$entries = [];
		foreach(array_splice($this->toClaim, 0, $room) as $command){
			$batch[$command->id] = $command;
			$entries[] = [$command->id, self::describe($command)];
		}
		$this->claiming += count($batch);
		$this->journal->claim($entries)->then(function(array $result) use ($batch) : void{
			$this->claiming -= count($batch);
			[$claimed, $others] = $result;
			$unrun = [];
			foreach($claimed as $id){
				if($this->closed){
					$unrun[] = $id;
				}else{
					$this->executor->enqueue($batch[$id]);
				}
			}
			foreach($others as $id => $state){
				unset($this->active[$id]);
				$this->states[$id] = $state;
				if($state === Journal::EXECUTED || $state === Journal::ACKED){
					$this->ack($id);
				}
			}
			if($unrun !== []){
				$this->journal->release($unrun)->catch($this->logFailure("Could not return unrun commands to the queue"));
			}
			$this->flushClaims();
		}, function(\Throwable $e) use ($batch) : void{
			$this->claiming -= count($batch);
			foreach($batch as $id => $_){
				unset($this->active[$id]);
			}
			throw $e;
		})->catch($this->logFailure("Could not claim commands"));
	}

	private function report(QueuedCommand $command, int $outcome, string $detail) : void{
		$id = $command->id;
		switch($outcome){
			case Executor::EXECUTED:
				$this->plugin->getLogger()->info("Executed command #$id for $command->playerName: $detail");
				$this->executed[] = $id;
				return;
			case Executor::BLOCKED:
				$this->debug("Command #$id was not run: $detail");
				$this->release($command);
				return;
			case Executor::REJECTED:
				$this->rejections[$id] = ($this->rejections[$id] ?? 0) + 1;
				if($this->rejections[$id] >= self::MAX_REJECTIONS){
					$this->quarantine($command, $detail);
				}else{
					$this->plugin->getLogger()->warning("Command #$id was refused ($detail), it will be tried again");
					$this->release($command);
				}
				return;
			default:
				$this->quarantine($command, $detail);
		}
	}

	private function drained() : void{
		$this->recordExecuted();
		$this->flushClaims();
	}

	/**
	 * Records the commands that ran this tick in one journal write, they are acknowledged once it is durable.
	 */
	private function recordExecuted() : void{
		if($this->executed === []){
			return;
		}
		$ids = $this->executed;
		$this->executed = [];
		$this->journal->mark($ids, Journal::EXECUTED)->then(function() use ($ids) : void{
			foreach($ids as $id){
				unset($this->active[$id], $this->dueAt[$id], $this->rejections[$id]);
				$this->states[$id] = Journal::EXECUTED;
				$this->ack($id);
			}
		}, function(\Throwable $e) use ($ids) : void{
			foreach($ids as $id){
				unset($this->active[$id]);
				$this->states[$id] = Journal::EXECUTING;
			}
			$this->plugin->getLogger()->error("Commands #" . implode(", #", $ids) . " ran but could not be recorded ({$e->getMessage()}), they stay unacknowledged and will be quarantined on restart");
		})->catch($this->logFailure("Could not record executed commands"));
	}

	private function release(QueuedCommand $command) : void{
		unset($this->active[$command->id]);
		$this->journal->release([$command->id])->catch($this->logFailure("Could not return command #$command->id to the queue"));
	}

	private function quarantine(QueuedCommand $command, string $reason) : void{
		unset($this->active[$command->id], $this->dueAt[$command->id], $this->rejections[$command->id]);
		$this->states[$command->id] = Journal::QUARANTINED;
		$this->plugin->getLogger()->warning("Quarantined command #$command->id for $command->playerName: $reason");
		$this->journal->mark([$command->id], Journal::QUARANTINED, self::describe($command) . " ($reason)")
			->catch($this->logFailure("Could not record quarantined command #$command->id"));
		if(TebexCommandQuarantinedEvent::hasHandlers()){
			(new TebexCommandQuarantinedEvent($this->plugin, $command, $reason))->call();
		}
	}

	private function ack(int $id) : void{
		$this->acks[$id] = true;
		if(!$this->acking && $this->ackTimer === null){
			$this->ackTimer = $this->later(1, $this->flushAcks(...));
		}
	}

	private function flushAcks() : void{
		$this->ackTimer = null;
		if($this->acking || $this->acks === []){
			return;
		}
		$ids = [];
		foreach($this->acks as $id => $_){
			$ids[] = $id;
			if(count($ids) >= $this->ackChunk){
				break;
			}
		}
		$this->acking = true;
		$this->api->request("DELETE", "/queue", ["ids" => $ids])->then(function() use ($ids) : void{
			$this->acking = false;
			$this->ackFailures = 0;
			$this->ackChunk = self::ACK_BATCH;
			$this->acknowledged($ids);
			$this->debug("Acknowledged " . count($ids) . " commands");
			if($this->acks !== []){
				$this->flushAcks();
			}elseif($this->offlineLimited){
				$this->fetchOffline();
			}
		}, function(\Throwable $e) use ($ids) : void{
			$this->acking = false;
			if(!$e instanceof TebexException || $e->isTransient()){
				$this->ackFailures++;
				$delay = max($e instanceof TebexException ? $e->retryAfter ?? 0 : 0, TebexApi::backoff($this->ackFailures, 2, 300));
				if($this->ackFailures === 1 || $this->ackFailures % 10 === 0){
					$this->plugin->getLogger()->warning("Could not acknowledge " . count($ids) . " commands: {$e->getMessage()}, retrying in {$delay}s");
				}
				$this->ackTimer = $this->later($delay, $this->flushAcks(...));
			}elseif($e->isUnauthorized()){
				$this->suspend($e);
			}elseif(count($ids) > 1){
				// Narrow down which ids Tebex refuses instead of retrying the whole batch forever.
				$this->ackChunk = max(1, intdiv(count($ids), 2));
				$this->flushAcks();
			}else{
				$this->plugin->getLogger()->warning("Tebex refused to acknowledge command #$ids[0]: {$e->getMessage()}");
				$this->acknowledged($ids);
				$this->flushAcks();
			}
		})->catch($this->logFailure("Acknowledgement failed"));
	}

	/**
	 * @param list<int> $ids
	 */
	private function acknowledged(array $ids) : void{
		// The journal still knows them, a redelivery is recognised when it is claimed.
		foreach($ids as $id){
			unset($this->acks[$id], $this->states[$id]);
		}
		$this->journal->mark($ids, Journal::ACKED)->catch($this->logFailure("Could not record acknowledgements"));
	}

	private function receiver(QueuedCommand $command) : ?Player{
		$player = $this->online[$this->byXuid ? $command->playerUuid : strtolower($command->playerName)] ?? null;
		if($player === null || !$player->isConnected()){
			return null;
		}
		$matches = $this->byXuid ? $player->getXuid() === $command->playerUuid : strcasecmp($player->getName(), $command->playerName) === 0;
		return $matches ? $player : null;
	}

	private function playerKey(Player $player) : ?string{
		if(!$this->byXuid){
			return strtolower($player->getName());
		}
		return $player->isAuthenticated() && $player->getXuid() !== "" ? $player->getXuid() : null;
	}

	private static function describe(QueuedCommand $command) : string{
		return mb_strcut("#$command->id $command->playerName: $command->command", 0, 400, "UTF-8");
	}

	/**
	 * @phpstan-param \Closure() : void $task
	 * @phpstan-return TaskHandler<ClosureTask>|null
	 */
	private function later(int $seconds, \Closure $task) : ?TaskHandler{
		if($this->closed){
			return null;
		}
		return $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask($task), max(1, $seconds * Server::TARGET_TICKS_PER_SECOND));
	}

	/**
	 * @return \Closure(\Throwable) : void
	 */
	private function logFailure(string $context) : \Closure{
		return function(\Throwable $e) use ($context) : void{
			if($this->closed && $e instanceof \RuntimeException){
				$this->debug("$context during shutdown: {$e->getMessage()}");
			}elseif($e instanceof \RuntimeException){
				$this->plugin->getLogger()->warning("$context: {$e->getMessage()}");
			}else{
				$this->plugin->getLogger()->logException($e);
			}
		};
	}

	private function debug(string $message) : void{
		if($this->debug){
			$this->plugin->getLogger()->info($message);
		}
	}
}
