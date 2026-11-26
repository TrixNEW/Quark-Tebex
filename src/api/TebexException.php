<?php

declare(strict_types=1);

namespace Trix\Tebex\api;

/**
 * A failed request. The status is 0 when no HTTP response was received.
 */
final class TebexException extends \RuntimeException {
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?int $retryAfter = null
    ) {
        parent::__construct($message, $status);
    }

    public function isRateLimited(): bool {
        return $this->status === 429;
    }

    public function isUnauthorized(): bool {
        return $this->status === 401 || $this->status === 403;
    }

    public function isTransient(): bool {
        return $this->status === 0 || $this->status === 408 || $this->status === 429 || $this->status >= 500;
    }
}
