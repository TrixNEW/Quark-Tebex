<?php

declare(strict_types=1);

namespace Trix\Tebex\event;

use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\event\plugin\PluginEvent;
use quark\player\Player;
use quark\plugin\Plugin;
use Trix\Tebex\QueuedCommand;
use function preg_match;

/**
 * Cancelling leaves the command queued for a later attempt.
 */
final class TebexCommandExecuteEvent extends PluginEvent implements Cancellable {
    use CancellableTrait;

    public function __construct(
        Plugin $plugin,
        private readonly QueuedCommand $command,
        private readonly ?Player $player,
        private string $commandLine
    ) {
        parent::__construct($plugin);
    }

    public function getCommand(): QueuedCommand {
        return $this->command;
    }

    /** The receiving player, null for offline commands. */
    public function getPlayer(): ?Player {
        return $this->player;
    }

    public function getCommandLine(): string {
        return $this->commandLine;
    }

    public function setCommandLine(string $commandLine): void {
        if ($commandLine === "" || preg_match('/[\x00-\x1F\x7F]/', $commandLine) === 1) {
            throw new \InvalidArgumentException("Command line must be a non-empty single line");
        }
        $this->commandLine = $commandLine;
    }
}
