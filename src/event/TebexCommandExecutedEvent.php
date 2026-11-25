<?php

declare(strict_types=1);

namespace Trix\Tebex\event;

use quark\event\plugin\PluginEvent;
use quark\player\Player;
use quark\plugin\Plugin;
use Trix\Tebex\QueuedCommand;

/**
 * Called after a purchase command ran without errors. The command decides whether the reward was actually given,
 * its replies are available through {@link getOutput()}.
 */
final class TebexCommandExecutedEvent extends PluginEvent{
	/**
	 * @param list<string> $output
	 */
	public function __construct(
		Plugin $plugin,
		private readonly QueuedCommand $command,
		private readonly ?Player $player,
		private readonly string $commandLine,
		private readonly array $output
	){
		parent::__construct($plugin);
	}

	public function getCommand() : QueuedCommand{
		return $this->command;
	}

	public function getPlayer() : ?Player{
		return $this->player;
	}

	public function getCommandLine() : string{
		return $this->commandLine;
	}

	/** @return list<string> */
	public function getOutput() : array{
		return $this->output;
	}
}
