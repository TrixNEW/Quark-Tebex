<?php

declare(strict_types=1);

namespace Trix\Tebex\api;

use quark\scheduler\AsyncPool;
use quark\scheduler\ClosureTask;
use quark\scheduler\TaskHandler;
use quark\scheduler\TaskScheduler;
use quark\Server;
use Vow\Deferred;
use Vow\Promise;
use function array_sum;
use function ceil;
use function hrtime;
use function http_build_query;
use function intdiv;
use function is_numeric;
use function json_decode;
use function max;
use function min;
use function preg_match;
use function random_int;
use function strtotime;
use function time;
use const JSON_THROW_ON_ERROR;
use const PHP_QUERY_RFC3986;

final class TebexApi {
    public const string BASE_URL = "https://plugin.tebex.io";

    // Tebex allows 500 requests per 5 minutes per key, leave headroom for manual commands.
    private const float RATE = 450 / 300;
    private const int BURST = 30;
    private const int TIMEOUT_MS = 10_000;
    private const int MAX_QUEUED_REQUESTS = 1024;

    private float $tokens = self::BURST;
    private float $refilledAt;
    private float $pausedUntil = 0.0;
    private bool $closed = false;
    /** @phpstan-var TaskHandler<ClosureTask>|null */
    private ?TaskHandler $wake = null;

    /** @var \SplQueue<array{string, string, string, Deferred<mixed>}> */
    private \SplQueue $queue;
    /** @var array<int, int> worker id => requests in flight */
    private array $inFlight = [];

    /**
     * @param list<int> $workers pool workers reserved for HTTP, one request in flight on each
     */
    public function __construct(
        private AsyncPool $pool,
        array $workers,
        private TaskScheduler $scheduler,
        #[\SensitiveParameter] private string $secret,
        private string $baseUrl = self::BASE_URL,
        private bool $allowHttp = false
    ) {
        $this->queue = new \SplQueue();
        $this->refilledAt = self::now();
        foreach ($workers as $worker) {
            $this->inFlight[$worker] = 0;
        }
    }

    /**
     * Sends a request and resolves with the decoded JSON body, or null for an empty one.
     * Rejects with {@link TebexException}.
     *
     * @param array<string, mixed> $form form fields sent as the request body
     * @phpstan-return Promise<mixed>
     */
    public function request(string $method, string $path, array $form = []): Promise {
        if ($this->closed) {
            return Promise::rejected(new TebexException("Tebex client is closed"));
        }
        if (preg_match('~^/[^\x00-\x20\x7F]*$~D', $path) !== 1) {
            return Promise::rejected(new TebexException("Tebex requests require an absolute API path"));
        }
        if ($this->queue->count() >= self::MAX_QUEUED_REQUESTS) {
            return Promise::rejected(new TebexException("Tebex request queue is full"));
        }
        /** @phpstan-var Deferred<mixed> $deferred */
        $deferred = new Deferred();
        $body = $form === [] ? "" : http_build_query($form, "", "&", PHP_QUERY_RFC3986);
        $this->queue->enqueue([$method, $path, $body, $deferred]);
        $this->pump();
        return $deferred->promise;
    }

    /** @phpstan-return Promise<mixed> */
    public function get(string $path): Promise {
        return $this->request("GET", $path);
    }

    public function pending(): int {
        return $this->queue->count() + (int) array_sum($this->inFlight);
    }

    public function close(): void {
        $this->closed = true;
        $this->wake?->cancel();
        $this->wake = null;
        while (!$this->queue->isEmpty()) {
            $this->queue->dequeue()[3]->reject(new TebexException("Tebex client is closed"));
        }
    }

    private function pump(): void {
        while (!$this->closed && !$this->queue->isEmpty()) {
            $worker = $this->idleWorker();
            if ($worker === null) return;
            $now = self::now();
            $this->tokens = min(self::BURST, $this->tokens + ($now - $this->refilledAt) * self::RATE);
            $this->refilledAt = $now;
            $wait = max($this->pausedUntil - $now, (1 - $this->tokens) / self::RATE);
            if ($wait > 0) {
                $this->scheduleWake($wait);
                return;
            }
            $this->tokens--;
            $this->send($worker, ...$this->queue->dequeue());
        }
    }

    private function idleWorker(): ?int {
        foreach ($this->inFlight as $worker => $count) {
            if ($count === 0) {
                return $worker;
            }
        }
        return null;
    }

    private function scheduleWake(float $seconds): void {
        if ($this->wake !== null) return;
        $this->wake = $this->scheduler->scheduleDelayedTask(new ClosureTask(function (): void {
            $this->wake = null;
            $this->pump();
        }), max(1, (int) ceil($seconds * Server::TARGET_TICKS_PER_SECOND)));
    }

    /**
     * @phpstan-param Deferred<mixed> $deferred
     */
    private function send(int $worker, string $method, string $path, string $body, Deferred $deferred): void {
        /** @phpstan-var Deferred<array{int, ?string, string, ?string}> $raw */
        $raw = new Deferred();
        $this->inFlight[$worker]++;
        $raw->promise->observe(function (array $result) use ($worker, $deferred): void {
            $this->inFlight[$worker]--;
            try {
                $data = self::decode(...$result);
            } catch (TebexException $e) {
                if ($e->isRateLimited()) {
                    $this->pausedUntil = max($this->pausedUntil, self::now() + ($e->retryAfter ?? 60));
                }
                $this->pump();
                $deferred->reject($e);
                return;
            }
            $this->pump();
            $deferred->resolve($data);
        });
        try {
            $this->pool->submitTaskToWorker(
                new HttpTask(
                    $method,
                    $this->baseUrl . $path,
                    $this->secret,
                    $body,
                    self::TIMEOUT_MS,
                    $this->allowHttp,
                    $raw
                ),
                $worker
            );
        } catch (\Throwable) {
            $this->inFlight[$worker]--;
            $deferred->reject(new TebexException("Could not submit a Tebex request"));
        }
    }

    /**
     * @throws TebexException
     */
    public static function decode(int $status, ?string $retryAfter, string $body, ?string $error): mixed {
        if ($error !== null) {
            throw new TebexException("Connection failed");
        }
        if ($status < 200 || $status >= 300) {
            throw new TebexException("Tebex returned HTTP $status", $status, self::retryAfter($retryAfter, time()));
        }
        try {
            return $body === "" ? null : json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TebexException("Malformed response from Tebex (HTTP $status)", $status);
        }
    }

    /**
     * Parses a Retry-After header given in seconds or as an HTTP date into seconds from now.
     */
    public static function retryAfter(?string $header, int $now): ?int {
        if ($header === null || $header === "") return null;
        if (is_numeric($header)) {
            return min(3600, max(0, (int) $header));
        }
        $at = strtotime($header);
        return $at === false ? null : min(3600, max(0, $at - $now));
    }

    /**
     * Exponential backoff with jitter, in seconds.
     */
    public static function backoff(int $failures, int $base = 5, int $cap = 300): int {
        $ceiling = min($cap, $base * (2 ** min(16, max(0, $failures - 1))));
        return max(1, intdiv($ceiling, 2) + random_int(0, intdiv($ceiling, 2)));
    }

    private static function now(): float {
        return hrtime(true) / 1e9;
    }
}
