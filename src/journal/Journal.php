<?php

declare(strict_types=1);

namespace Trix\Tebex\journal;

use quark\scheduler\AsyncPool;
use Vow\Deferred;
use Vow\Promise;
use function array_splice;
use function count;

final class Journal {
    public const int PENDING = 0;
    public const int EXECUTING = 1;
    public const int EXECUTED = 2;
    public const int ACKED = 3;
    public const int QUARANTINED = 4;

    /** @var list<list<mixed>> */
    private array $ops = [];
    /** @var list<Deferred<mixed>> */
    private array $waiting = [];
    private bool $writing = false;
    private bool $closed = false;

    public function __construct(
        private AsyncPool $pool,
        private int $worker,
        private string $path,
        private string $scope,
        private string $owner
    ) {}

    /**
     * Quarantines commands left running by a previous run and returns every unacknowledged command.
     *
     * @phpstan-return Promise<list<array{int, int, int}>>
     */
    public function load(): Promise {
        // @phpstan-ignore return.type
        return $this->submit(["load"]);
    }

    /**
     * @param list<array{int, string}> $commands id and description
     * @phpstan-return Promise<array{list<int>, array<int, int>}>
     */
    public function claim(array $commands): Promise {
        // @phpstan-ignore return.type
        return $this->submit(["claim", $commands]);
    }

    /**
     * @param list<array{int, int}> $commands id and due time
     * @phpstan-return Promise<array<int, int>>
     */
    public function defer(array $commands): Promise {
        // @phpstan-ignore return.type
        return $this->submit(["defer", $commands]);
    }

    /**
     * @param list<int> $ids
     * @phpstan-return Promise<int>
     */
    public function mark(array $ids, int $state, ?string $detail = null): Promise {
        // @phpstan-ignore return.type
        return $this->submit(["mark", $ids, $state, $detail]);
    }

    /**
     * @param list<int> $ids
     * @phpstan-return Promise<int>
     */
    public function release(array $ids): Promise {
        // @phpstan-ignore return.type
        return $this->submit(["release", $ids]);
    }

    /** @phpstan-return Promise<bool> */
    public function resolve(int $id, bool $delivered): Promise {
        // @phpstan-ignore return.type
        return $this->submit(["resolve", $id, $delivered]);
    }

    /** @phpstan-return Promise<list<array{int, int, string}>> */
    public function quarantined(): Promise {
        // @phpstan-ignore return.type
        return $this->submit(["list", self::QUARANTINED]);
    }

    public function close(): void {
        $this->closed = true;
    }

    public function pending(): int {
        return count($this->ops) + ($this->writing ? 1 : 0);
    }

    /**
     * @param list<mixed> $op
     * @phpstan-return Promise<mixed>
     */
    private function submit(array $op): Promise {
        if ($this->closed) {
            return Promise::rejected(new \RuntimeException("Journal is closed"));
        }
        /** @phpstan-var Deferred<mixed> $deferred */
        $deferred = new Deferred();
        $this->ops[] = $op;
        $this->waiting[] = $deferred;
        $this->flush();
        return $deferred->promise;
    }

    private function flush(): void {
        if ($this->writing || $this->ops === []) return;
        $ops = array_splice($this->ops, 0);
        $waiting = array_splice($this->waiting, 0);
        // Queue the next transaction before callbacks can submit more writes.
        $this->writing = true;
        /** @phpstan-var Deferred<list<mixed>> $batch */
        $batch = new Deferred();
        $batch->promise->observe(function (array $results) use ($waiting): void {
            $this->writing = false;
            $this->flush();
            foreach ($waiting as $i => $deferred) {
                $deferred->resolve($results[$i]);
            }
        }, function (\Throwable $e) use ($waiting): void {
            $this->writing = false;
            $this->flush();
            foreach ($waiting as $deferred) {
                $deferred->reject($e);
            }
        });
        try {
            $this->pool->submitTaskToWorker(
                new JournalTask($this->path, $this->scope, $this->owner, $ops, $batch),
                $this->worker
            );
        } catch (\Throwable) {
            $batch->reject(new \RuntimeException("Could not submit a journal write"));
        }
    }
}
