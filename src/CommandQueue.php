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
use Vow\Deferred;
use Vow\Promise;
use function array_splice;
use function count;
use function ctype_digit;
use function intdiv;
use function is_array;
use function is_bool;
use function is_int;
use function max;
use function min;
use function strcasecmp;
use function strtolower;
use function time;

final class CommandQueue {
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
    /** @var list<Deferred<int>> */
    private array $refreshes = [];

    /** @var array<int|string, array{id: int, name: string, uuid: string}> */
    private array $due = [];
    /** @var array<int|string, Player> */
    private array $online = [];
    /** @var array<int, int> */
    private array $onlineFetchedAt = [];
    private bool $fetchingOffline = false;
    private bool $offlineLimited = false;

    /** @var array<int, QueuedCommand> */
    private array $active = [];
    /** @var array<int, int> */
    private array $states = [];
    /** @var array<int, int> */
    private array $dueAt = [];
    /** @var array<int, int> */
    private array $rejections = [];
    /** @var \SplQueue<QueuedCommand> */
    private \SplQueue $toClaim;
    private int $claiming = 0;
    private int $claimWindow;
    /** @var \SplPriorityQueue<array{int, int}, array{QueuedCommand, int}> */
    private \SplPriorityQueue $timers;
    /** @phpstan-var TaskHandler<ClosureTask>|null */
    private ?TaskHandler $timer = null;
    private int $timerAt = 0;
    private int $sequence = 0;

    /** @var list<int> */
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
        int $perTick
    ) {
        $this->byXuid = $store->matchesByXuid() ?? throw new \InvalidArgumentException("Unsupported store type");
        // Limit how many unrun commands a crash can leave claimed.
        $this->claimWindow = $perTick * intdiv(Server::TARGET_TICKS_PER_SECOND, 2);
        $this->toClaim = new \SplQueue();
        $this->timers = new \SplPriorityQueue();
        $this->timers->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        $this->executor = new Executor(
            $plugin,
            $plugin->getServer()->getCommandMap(),
            $sender,
            $perTick,
            $this->receiver(...),
            $this->report(...),
            $this->drained(...)
        );
    }

    public function getStore(): Store {
        return $this->store;
    }

    /**
     * @phpstan-return Promise<null>
     */
    public function start(): Promise {
        return $this->journal->load()->then(function (array $rows): null {
            if ($this->closed) return null;
            $quarantined = 0;
            foreach ($rows as [$id, $state, $due]) {
                if ($state === Journal::PENDING) {
                    $this->dueAt[$id] = $due;
                    continue;
                }
                if ($state === Journal::EXECUTED) {
                    $this->states[$id] = $state;
                    $this->ack($id);
                } elseif ($state === Journal::QUARANTINED) {
                    $this->states[$id] = $state;
                    $quarantined++;
                }
            }
            if ($quarantined > 0) {
                $commands = $quarantined === 1 ? "command needs" : "commands need";
                $this->plugin->getLogger()->warning("$quarantined purchase $commands review. See /tebex quarantine.");
            }
            foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
                $this->onJoin($player);
            }
            $this->poll();
            return null;
        });
    }

    public function close(): void {
        if ($this->closed) return;

        $this->closed = true;
        foreach (array_splice($this->refreshes, 0) as $refresh) {
            $refresh->reject(new \RuntimeException("Tebex is shutting down"));
        }
        $this->recordExecuted();
        foreach ([$this->pollTimer, $this->timer, $this->ackTimer] as $handler) {
            $handler?->cancel();
        }
        $this->pollTimer = $this->timer = $this->ackTimer = null;
        $unrun = [];
        foreach ($this->executor->clear() as $command) {
            $unrun[] = $command->id;
        }
        $this->toClaim = new \SplQueue();
        $this->active = $this->online = $this->due = [];
        if ($unrun !== []) {
            $this->journal->release($unrun)->catch($this->logFailure("Could not return unrun commands to the queue"));
        }
        if ($this->acks !== [] && !$this->acking) {
            $this->flushAcks();
        }
    }

    public function onJoin(Player $player): void {
        if ($this->closed) return;

        $key = $this->playerKey($player);
        if ($key === null) return;
        $this->online[$key] = $player;
        if (isset($this->due[$key])) {
            $this->fetchOnline($this->due[$key]);
        }
    }

    public function onQuit(Player $player): void {
        $key = $this->playerKey($player);
        if ($key !== null && ($this->online[$key] ?? null) === $player) {
            unset($this->online[$key]);
        }
    }

    /**
     * Joins the current check or starts one after the cooldown.
     *
     * @phpstan-return Promise<int>
     */
    public function refresh(): Promise {
        if ($this->closed) {
            return Promise::rejected(new \RuntimeException("Tebex is shutting down"));
        }
        $this->suspended = false;
        if ($this->polling === null) {
            $wait = $this->lastPollAt + self::REFRESH_COOLDOWN - time();
            if ($wait > 0) {
                if ($this->pollTimer === null || $this->nextPollAt > time() + $wait) {
                    $this->schedulePoll($wait);
                }
                /** @phpstan-var Deferred<int> $deferred */
                $deferred = new Deferred();
                $this->refreshes[] = $deferred;
                return $deferred->promise;
            }
        }
        return $this->poll()->then(fn(): int => count($this->due));
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    public function status(): array {
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
    public function quarantined(): Promise {
        return $this->journal->quarantined();
    }

    /**
     * Settles a quarantined command. Delivered commands are acknowledged, the others run again on the next check.
     *
     * @phpstan-return Promise<bool>
     */
    public function resolve(int $id, bool $delivered): Promise {
        return $this->journal->resolve($id, $delivered)->then(function (bool $resolved) use ($id, $delivered): bool {
            if ($resolved) {
                unset($this->rejections[$id], $this->dueAt[$id]);
                if ($delivered) {
                    $this->states[$id] = Journal::EXECUTED;
                    $this->ack($id);
                } else{
                    unset($this->states[$id]);
                }
            }
            return $resolved;
        });
    }

    /**
     * @phpstan-return Promise<int>
     */
    private function poll(): Promise {
        if ($this->polling !== null) {
            return $this->polling;
        }
        $this->pollTimer?->cancel();
        $this->pollTimer = null;
        $promise = Promise::async(function (): int {
            $players = [];
            $executeOffline = false;
            $next = 60;
            for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
                $data = $this->api->get($page === 1 ? "/queue" : "/queue?page=$page")->await();
                if ($this->closed) return $next;

                if (!is_array($data) || !is_array($data["meta"] ?? null) || !is_array($data["players"] ?? null)) {
                    throw new TebexException("Malformed queue from Tebex");
                }
                $meta = $data["meta"];
                if ($page === 1) {
                    $next = is_int($meta["next_check"] ?? null) ? $meta["next_check"] : 60;
                    $executeOffline = is_bool($meta["execute_offline"] ?? null) && $meta["execute_offline"];
                }
                $added = false;
                foreach ($data["players"] as $entry) {
                    $player = QueuedCommand::parsePlayer($entry);
                    if ($player !== null && !isset($players[$player["id"]])) {
                        $players[$player["id"]] = $player;
                        $added = true;
                    }
                }
                if (($meta["more"] ?? false) !== true || !$added) {
                    break;
                }
            }
            $this->setDue($players);
            if ($executeOffline) {
                $this->fetchOffline();
            }
            return $next;
        });
        $this->polling = $promise;
        $promise->then(function (int $next): void {
            $this->polling = null;
            if ($this->closed) return;

            $due = count($this->due);
            foreach (array_splice($this->refreshes, 0) as $refresh) {
                $refresh->resolve($due);
            }
            $this->failures = 0;
            $this->lastError = null;
            $this->lastPollAt = time();
            $next = max(10, min(3600, $next));
            $this->plugin->getLogger()->debug("Checked the queue: $due due players, next check in {$next}s");
            $this->schedulePoll($next);
            if ($this->acks !== [] && !$this->acking && $this->ackTimer === null) {
                $this->flushAcks();
            }
        }, function (\Throwable $e): void {
            $this->polling = null;
            if ($this->closed) return;

            foreach (array_splice($this->refreshes, 0) as $refresh) {
                $refresh->reject($e);
            }
            $this->lastPollAt = time();
            $this->failures++;
            $this->lastError = $e instanceof TebexException ? $e->getMessage() : "Unexpected queue check failure";
            if ($e instanceof TebexException && $e->isUnauthorized()) {
                $this->suspend();
                return;
            }
            $delay = max(
                $e instanceof TebexException ? $e->retryAfter ?? 0 : 0,
                TebexApi::backoff($this->failures, 10, 600)
            );
            if ($this->failures === 1 || $this->failures % 10 === 0) {
                $this->plugin->getLogger()->warning(
                    "Could not check the Tebex queue: {$this->lastError}. Retrying in {$delay}s"
                );
            }
            $this->schedulePoll($delay);
        })->catch($this->logFailure("Queue check failed"));
        return $promise;
    }

    private function schedulePoll(int $seconds): void {
        if ($this->closed || $this->suspended) return;

        $this->pollTimer?->cancel();
        $this->nextPollAt = time() + $seconds;
        $this->pollTimer = $this->later($seconds, function (): void {
            $this->pollTimer = null;
            $this->poll();
        });
    }

    private function suspend(): void {
        if ($this->closed || $this->suspended) return;

        $this->suspended = true;
        $this->pollTimer?->cancel();
        $this->pollTimer = null;
        $this->nextPollAt = 0;
        $this->plugin->getLogger()->error("Tebex refused the secret key. Queue checks are paused until /tebex refresh.");
    }

    /**
     * @param array<int, array{id: int, name: string, uuid: string}> $players
     */
    private function setDue(array $players): void {
        $this->due = [];
        $fetchedAt = [];
        foreach ($players as $player) {
            if (isset($this->onlineFetchedAt[$player["id"]])) {
                $fetchedAt[$player["id"]] = $this->onlineFetchedAt[$player["id"]];
            }
            $key = $this->byXuid ? (ctype_digit($player["uuid"]) ? $player["uuid"] : null) : strtolower($player["name"]);
            if ($key !== null) {
                $this->due[$key] = $player;
            }
        }
        $this->onlineFetchedAt = $fetchedAt;
        foreach ($this->online as $key => $_) {
            if (isset($this->due[$key])) {
                $this->fetchOnline($this->due[$key]);
            }
        }
    }

    /**
     * @param array{id: int, name: string, uuid: string} $player
     */
    private function fetchOnline(array $player): void {
        $now = time();
        if (
            $this->closed || $this->suspended ||
            ($this->onlineFetchedAt[$player["id"]] ?? 0) > $now - self::ONLINE_COOLDOWN
        ) {
            return;
        }
        $this->onlineFetchedAt[$player["id"]] = $now;
        $this->api->get("/queue/online-commands/" . $player["id"])->then(function (mixed $data) use ($player): void {
            if ($this->closed || $this->suspended) return;

            $this->offer($this->parseCommands($data, $player));
        })->catch($this->logFailure("Could not fetch online commands for Tebex player #{$player["id"]}"));
    }

    private function fetchOffline(): void {
        if ($this->closed || $this->suspended || $this->fetchingOffline) return;
        $this->fetchingOffline = true;
        $this->api->get("/queue/offline-commands")->then(function (mixed $data): void {
            $this->fetchingOffline = false;
            if ($this->closed || $this->suspended) return;

            $meta = is_array($data) ? $data["meta"] ?? null : null;
            $this->offlineLimited = is_array($meta) && ($meta["limited"] ?? false) === true;
            $this->offer($this->parseCommands($data, null));
        }, function (\Throwable $e): void {
            $this->fetchingOffline = false;
            throw $e;
        })->catch($this->logFailure("Could not fetch offline commands"));
    }

    /**
     * @param array{id: int, name: string, uuid: string}|null $player
     * @return list<QueuedCommand>
     */
    private function parseCommands(mixed $data, ?array $player): array {
        $entries = is_array($data) ? $data["commands"] ?? null : null;
        if (!is_array($entries)) {
            throw new TebexException("Malformed command list from Tebex");
        }
        $commands = [];
        $malformed = 0;
        foreach ($entries as $entry) {
            $command = QueuedCommand::parse($entry, $player);
            if ($command === null) {
                ++$malformed;
                continue;
            }
            $commands[] = $command;
        }
        if ($malformed > 0) {
            $this->plugin->getLogger()->warning("Skipped $malformed malformed commands from Tebex");
        }
        return $commands;
    }

    /**
     * @param list<QueuedCommand> $commands
     */
    private function offer(array $commands): void {
        if ($this->closed || $this->suspended) return;
        $deferred = [];
        $blocked = [];
        foreach ($commands as $command) {
            $id = $command->id;
            $payment = $command->payment;
            if (isset($this->active[$id])) {
                if ($payment !== null && $command->delay > 0) {
                    $blocked[$payment] = true;
                }
                continue;
            }
            $state = $this->states[$id] ?? null;
            if ($state === Journal::EXECUTED || $state === Journal::ACKED) {
                $this->ack($id);
                continue;
            }
            if ($state !== null) {
                if ($payment !== null) {
                    $blocked[$payment] = true;
                }
                continue;
            }
            if ($payment !== null && isset($blocked[$payment])) continue;

            $this->active[$id] = $command;
            if ($command->delay === 0) {
                $this->ready($command, $blocked);
            } elseif (isset($this->dueAt[$id])) {
                $this->schedule($command, $this->dueAt[$id], $blocked);
            } else{
                $deferred[] = $command;
                if ($payment !== null) {
                    $blocked[$payment] = true;
                }
            }
        }
        $this->flushClaims();
        if ($deferred === []) return;
        // Round up so a command never runs before its delay elapses.
        $now = time() + 1;
        $entries = [];
        foreach ($deferred as $command) {
            $entries[] = [$command->id, $now + $command->delay];
        }
        $this->journal->defer($entries)->then(function (array $due) use ($deferred, $now): void {
            if ($this->closed) return;

            $blocked = [];
            foreach ($deferred as $command) {
                $id = $command->id;
                if (($this->active[$id] ?? null) !== $command) continue;

                $this->dueAt[$id] = $due[$id] ?? $now + $command->delay;
                $this->schedule($command, $this->dueAt[$id], $blocked);
            }
            $this->flushClaims();
        }, function (\Throwable $e) use ($deferred): void {
            foreach ($deferred as $command) {
                unset($this->active[$command->id]);
            }
            throw $e;
        })->catch($this->logFailure("Could not record delayed commands"));
    }

    /**
     * @param array<int, true> $blocked payments with a command that is waiting
     */
    private function schedule(QueuedCommand $command, int $at, array &$blocked): void {
        if ($at <= time()) {
            $this->ready($command, $blocked);
            return;
        }
        $this->timers->insert([$command, $at], [-$at, -++$this->sequence]);
        if ($command->payment !== null) {
            $blocked[$command->payment] = true;
        }
        if ($this->timer === null || $at < $this->timerAt) {
            $this->armTimer();
        }
    }

    private function armTimer(): void {
        $this->timer?->cancel();
        $this->timer = null;
        if (!$this->timers->isEmpty()) {
            /** @var array{QueuedCommand, int} $next */
            $next = $this->timers->top();
            $this->timerAt = $next[1];
            $this->timer = $this->later(min(3600, $this->timerAt - time()), $this->fireTimers(...));
        }
    }

    private function fireTimers(): void {
        $this->timer = null;
        $now = time();
        $blocked = [];
        while (!$this->timers->isEmpty()) {
            /** @var array{QueuedCommand, int} $entry */
            $entry = $this->timers->top();
            if ($entry[1] > $now) {
                break;
            }
            $this->timers->extract();
            $command = $entry[0];
            if (($this->active[$command->id] ?? null) === $command) {
                $this->ready($command, $blocked);
            }
        }
        $this->flushClaims();
        $this->armTimer();
    }

    /**
     * @param array<int, true> $blocked
     */
    private function ready(QueuedCommand $command, array &$blocked): void {
        $payment = $command->payment;
        if ($payment !== null && isset($blocked[$payment])) {
            unset($this->active[$command->id]);
            return;
        }

        $player = $command->online ? $this->receiver($command) : null;
        if ($this->executor->blocker($command, $player) !== null) {
            unset($this->active[$command->id]);
            if ($payment !== null) {
                $blocked[$payment] = true;
            }
            if ($command->slots > 0 && $player !== null) {
                $player->sendMessage("Free up $command->slots inventory slots to receive your purchase.");
            }
            return;
        }
        $this->toClaim->enqueue($command);
    }

    private function flushClaims(): void {
        $room = $this->claimWindow - $this->claiming - $this->executor->queued();
        if ($this->closed || $room <= 0 || $this->toClaim->isEmpty()) return;
        $batch = [];
        $entries = [];
        for ($i = 0; $i < $room && !$this->toClaim->isEmpty(); ++$i) {
            $command = $this->toClaim->dequeue();
            $batch[$command->id] = $command;
            $entries[] = [$command->id, ""];
        }
        $this->claiming += count($batch);
        $this->journal->claim($entries)->then(function (array $result) use ($batch): void {
            $this->claiming -= count($batch);
            [$claimed, $others] = $result;
            $unrun = [];
            foreach ($claimed as $id) {
                if ($this->closed) {
                    $unrun[] = $id;
                } else{
                    $this->executor->enqueue($batch[$id]);
                }
            }
            foreach ($others as $id => $state) {
                unset($this->active[$id]);
                if ($state !== Journal::EXECUTING) {
                    $this->states[$id] = $state;
                }
                if ($state === Journal::EXECUTED || $state === Journal::ACKED) {
                    $this->ack($id);
                }
            }
            if ($unrun !== []) {
                $this->journal->release($unrun)->catch($this->logFailure("Could not return unrun commands to the queue"));
            }
            $this->flushClaims();
        }, function (\Throwable $e) use ($batch): void {
            $this->claiming -= count($batch);
            foreach ($batch as $id => $_) {
                unset($this->active[$id]);
            }
            throw $e;
        })->catch($this->logFailure("Could not claim commands"));
    }

    private function report(QueuedCommand $command, int $outcome, string $detail): void {
        $id = $command->id;
        switch ($outcome) {
            case Executor::EXECUTED:
                $this->executed[] = $id;
                $this->plugin->getLogger()->info("Executed purchase command #$id");
                return;
            case Executor::BLOCKED:
                $this->plugin->getLogger()->debug("Purchase command #$id is waiting: $detail");
                $this->release($command);
                return;
            case Executor::REJECTED:
                $this->rejections[$id] = ($this->rejections[$id] ?? 0) + 1;
                if ($this->rejections[$id] >= self::MAX_REJECTIONS) {
                    $this->quarantine($command, $detail);
                } else{
                    $this->plugin->getLogger()->warning("Command #$id was refused ($detail). Retrying on a later queue check.");
                    $this->release($command);
                }
                return;
            default:
                $this->quarantine($command, $detail);
        }
    }

    private function drained(): void {
        $this->recordExecuted();
        if ($this->claiming === 0 && $this->toClaim->isEmpty() && $this->executor->queued() === 0) {
            $this->executor->resetPaymentBlocks();
        }
        $this->flushClaims();
    }

    /**
     * Acknowledge only after the journal commits.
     */
    private function recordExecuted(): void {
        if ($this->executed === []) return;
        $ids = $this->executed;
        $this->executed = [];
        $this->journal->mark($ids, Journal::EXECUTED)->then(function () use ($ids): void {
            foreach ($ids as $id) {
                unset($this->active[$id], $this->dueAt[$id], $this->rejections[$id]);
                $this->states[$id] = Journal::EXECUTED;
                $this->ack($id);
            }
        }, function () use ($ids): void {
            foreach ($ids as $id) {
                unset($this->active[$id]);
                $this->states[$id] = Journal::EXECUTING;
            }
            $firstId = $ids[0];
            $count = count($ids);
            $this->plugin->getLogger()->error(
                "Could not record $count executed commands starting at #$firstId. They will be quarantined on restart."
            );
        })->catch($this->logFailure("Could not record executed commands"));
    }

    private function release(QueuedCommand $command): void {
        unset($this->active[$command->id]);
        $this->journal->release([$command->id])
            ->catch($this->logFailure("Could not return command #$command->id to the queue"));
    }

    private function quarantine(QueuedCommand $command, string $reason): void {
        unset($this->active[$command->id], $this->dueAt[$command->id], $this->rejections[$command->id]);
        $this->states[$command->id] = Journal::QUARANTINED;
        $this->plugin->getLogger()->error("Quarantined command #$command->id: $reason");
        $this->journal->mark([$command->id], Journal::QUARANTINED, $reason)
            ->catch($this->logFailure("Could not record quarantined command #$command->id"));
        if (TebexCommandQuarantinedEvent::hasHandlers()) {
            try {
                (new TebexCommandQuarantinedEvent($this->plugin, $command, $reason))->call();
            } catch (\Throwable) {
                $this->plugin->getLogger()->warning("A quarantine listener failed for command #$command->id");
            }
        }
    }

    private function ack(int $id): void {
        $this->acks[$id] = true;
        if (!$this->acking && $this->ackTimer === null) {
            $this->ackTimer = $this->later(1, $this->flushAcks(...));
        }
    }

    private function flushAcks(): void {
        $this->ackTimer = null;
        if ($this->acking || $this->suspended || $this->acks === []) return;
        $ids = [];
        foreach ($this->acks as $id => $_) {
            $ids[] = $id;
            if (count($ids) >= $this->ackChunk) {
                break;
            }
        }
        $this->acking = true;
        $this->api->request("DELETE", "/queue", ["ids" => $ids])->then(function () use ($ids): void {
            $this->acking = false;
            $this->ackFailures = 0;
            $this->ackChunk = self::ACK_BATCH;
            $this->acknowledged($ids);
            $this->plugin->getLogger()->debug("Acknowledged " . count($ids) . " purchase commands");
            if ($this->closed) return;

            if ($this->acks !== []) {
                $this->flushAcks();
            } elseif ($this->offlineLimited) {
                $this->fetchOffline();
            }
        }, function (\Throwable $e) use ($ids): void {
            $this->acking = false;
            if ($this->closed) return;

            if ($e instanceof TebexException) {
                if ($e->isUnauthorized()) {
                    $this->suspend();
                    return;
                }
                if (!$e->isTransient()) {
                    if (count($ids) > 1) {
                        $this->ackChunk = intdiv(count($ids), 2);
                        $this->flushAcks();
                        return;
                    }

                    // Keep the rejected ID durable and give other IDs a chance to be acknowledged.
                    $id = $ids[0];
                    unset($this->acks[$id]);
                    $this->acks[$id] = true;
                    $this->ackChunk = self::ACK_BATCH;
                }
            }

            $delay = max(
                $e instanceof TebexException ? $e->retryAfter ?? 0 : 0,
                TebexApi::backoff(++$this->ackFailures, 2, 300)
            );
            if ($this->ackFailures === 1 || $this->ackFailures % 10 === 0) {
                $detail = $e instanceof TebexException ? $e->getMessage() : "Unexpected request failure";
                $count = count($ids);
                $this->plugin->getLogger()->warning(
                    "Could not acknowledge $count purchase commands: $detail. Retrying in {$delay}s"
                );
            }
            $this->ackTimer = $this->later($delay, $this->flushAcks(...));
        })->catch($this->logFailure("Acknowledgement failed"));
    }

    /**
     * @param list<int> $ids
     */
    private function acknowledged(array $ids): void {
        foreach ($ids as $id) {
            unset($this->acks[$id], $this->states[$id]);
        }
        $this->journal->mark($ids, Journal::ACKED)->catch($this->logFailure("Could not record acknowledgements"));
    }

    private function receiver(QueuedCommand $command): ?Player {
        $player = $this->online[$this->byXuid ? $command->playerUuid : strtolower($command->playerName)] ?? null;
        if ($player === null || !$player->isConnected()) return null;
        $matches = $this->byXuid
            ? $player->getXuid() === $command->playerUuid
            : strcasecmp($player->getName(), $command->playerName) === 0;
        return $matches ? $player : null;
    }

    private function playerKey(Player $player): ?string {
        if (!$this->byXuid) {
            return strtolower($player->getName());
        }
        return $player->isAuthenticated() && $player->getXuid() !== "" ? $player->getXuid() : null;
    }

    /**
     * @phpstan-param \Closure(): void $task
     * @phpstan-return TaskHandler<ClosureTask>|null
     */
    private function later(int $seconds, \Closure $task): ?TaskHandler {
        if ($this->closed) return null;
        return $this->plugin->getScheduler()->scheduleDelayedTask(
            new ClosureTask($task),
            max(1, $seconds * Server::TARGET_TICKS_PER_SECOND)
        );
    }

    /**
     * @return \Closure(\Throwable): void
     */
    private function logFailure(string $context): \Closure {
        return function (\Throwable $e) use ($context): void {
            if ($this->closed && $e instanceof TebexException) {
                $this->plugin->getLogger()->debug("$context during shutdown");
            } elseif ($e instanceof TebexException && $e->isUnauthorized()) {
                $this->suspend();
            } elseif ($e instanceof TebexException) {
                $this->plugin->getLogger()->warning("$context: {$e->getMessage()}");
            } else{
                $this->plugin->getLogger()->error($context);
            }
        };
    }
}
