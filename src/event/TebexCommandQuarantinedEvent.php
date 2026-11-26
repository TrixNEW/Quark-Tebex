<?php

declare(strict_types=1);

namespace Trix\Tebex\event;

use quark\event\plugin\PluginEvent;
use quark\plugin\Plugin;
use Trix\Tebex\QueuedCommand;

/**
 * A quarantined command needs manual resolution before it can run or be acknowledged.
 */
final class TebexCommandQuarantinedEvent extends PluginEvent {
    public function __construct(
        Plugin $plugin,
        private readonly QueuedCommand $command,
        private readonly string $reason
    ) {
        parent::__construct($plugin);
    }

    public function getCommand(): QueuedCommand {
        return $this->command;
    }

    public function getReason(): string {
        return $this->reason;
    }
}
