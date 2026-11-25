<?php

declare(strict_types=1);

namespace Trix\Tebex;

use quark\command\internal\CommandInputException;
use quark\command\SimpleCommandMap;
use quark\command\utils\CommandStringHelper;
use quark\command\utils\InvalidCommandSyntaxException;
use quark\player\Player;
use quark\plugin\Plugin;
use quark\plugin\PluginOwned;
use quark\scheduler\ClosureTask;
use quark\scheduler\TaskHandler;
use Trix\Tebex\event\TebexCommandExecutedEvent;
use Trix\Tebex\event\TebexCommandExecuteEvent;
use function array_shift;
use function hrtime;
use function count;
use function implode;
use function ltrim;
use function preg_match;
use function preg_replace_callback;
use function str_contains;
use function strlen;
use function trim;

/**
 * Runs claimed commands through the Tebex sender. Each tick runs up to a number of commands and stops early once
 * they took a few milliseconds, so heavy commands cannot drop TPS while cheap ones still clear a backlog fast.
 */
final class Executor{
	private const int TICK_BUDGET_NS = 5_000_000;

	public const int EXECUTED = 0;
	/** Conditions are not met right now, the command may run later. */
	public const int BLOCKED = 1;
	/** The command line was refused before anything ran. */
	public const int REJECTED = 2;
	/** The command cannot run safely, or may have partly run. */
	public const int QUARANTINED = 3;

	/** @var \SplQueue<QueuedCommand> */
	private \SplQueue $queue;
	/** @phpstan-var TaskHandler<ClosureTask>|null */
	private ?TaskHandler $task = null;

	/**
	 * @param \Closure(QueuedCommand) : ?Player $player finds the online receiver of a command
	 * @param \Closure(QueuedCommand, int, string) : void $report receives the outcome of every command
	 * @param \Closure() : void $drained called after each tick's commands ran
	 */
	public function __construct(
		private Plugin $plugin,
		private SimpleCommandMap $commandMap,
		private TebexSender $sender,
		private int $perTick,
		private \Closure $player,
		private \Closure $report,
		private \Closure $drained
	){
		$this->queue = new \SplQueue();
	}

	public function enqueue(QueuedCommand $command) : void{
		$this->queue->enqueue($command);
		$this->task ??= $this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask($this->drain(...)), 1);
	}

	public function queued() : int{
		return $this->queue->count();
	}

	/**
	 * Returns why the command cannot run now, or null if it can.
	 */
	public function blocker(QueuedCommand $command, ?Player $player) : ?string{
		if(!$command->online){
			return null;
		}
		if($player === null || !$player->isConnected()){
			return "player is offline";
		}
		if($command->slots > 0){
			$inventory = $player->getInventory();
			if($inventory->getSize() - count($inventory->getContents()) < $command->slots){
				return "needs $command->slots free inventory slots";
			}
		}
		return null;
	}

	public function drain() : void{
		$deadline = hrtime(true) + self::TICK_BUDGET_NS;
		for($i = 0; $i < $this->perTick && !$this->queue->isEmpty() && ($i === 0 || hrtime(true) < $deadline); ++$i){
			$command = $this->queue->dequeue();
			[$outcome, $detail, $player, $output] = $this->run($command);
			($this->report)($command, $outcome, $detail);
			// Called after the outcome is reported, so a failing listener cannot leave a run command unrecorded.
			if($outcome === self::EXECUTED && TebexCommandExecutedEvent::hasHandlers()){
				(new TebexCommandExecutedEvent($this->plugin, $command, $player, $detail, $output))->call();
			}
		}
		($this->drained)();
		if($this->queue->isEmpty()){
			$this->task?->cancel();
			$this->task = null;
		}
	}

	/**
	 * Removes every command that has not run yet.
	 *
	 * @return list<QueuedCommand>
	 */
	public function clear() : array{
		$this->task?->cancel();
		$this->task = null;
		$commands = [];
		while(!$this->queue->isEmpty()){
			$commands[] = $this->queue->dequeue();
		}
		return $commands;
	}

	/**
	 * @return array{int, string, ?Player, list<string>} outcome, command line or reason, receiver and replies
	 */
	private function run(QueuedCommand $command) : array{
		$player = $command->online ? ($this->player)($command) : null;
		if(($blocker = $this->blocker($command, $player)) !== null){
			return [self::BLOCKED, $blocker, null, []];
		}
		$line = self::render($command->command, $player?->getName() ?? $command->playerName, $command->playerUuid);
		if($line === null){
			return [self::QUARANTINED, "unsafe command or placeholder value", null, []];
		}
		if(TebexCommandExecuteEvent::hasHandlers()){
			$event = new TebexCommandExecuteEvent($this->plugin, $command, $player, $line);
			$event->call();
			if($event->isCancelled()){
				return [self::BLOCKED, "cancelled by a plugin", null, []];
			}
			$line = $event->getCommandLine();
		}

		$args = CommandStringHelper::parseQuoteAware($line);
		$label = array_shift($args) ?? "";
		$target = $this->commandMap->getCommand($label);
		if($target === null){
			return [self::REJECTED, "unknown command /$label", null, []];
		}
		if($target instanceof PluginOwned && !$target->getOwningPlugin()->isEnabled()){
			return [self::REJECTED, "/$label belongs to a disabled plugin", null, []];
		}
		if(!$target->testPermissionSilent($this->sender)){
			return [self::REJECTED, "the Tebex sender may not use /$label", null, []];
		}

		$this->sender->takeOutput();
		try{
			$target->dispatchLine($this->sender, $label, $args, $line);
		}catch(CommandInputException | InvalidCommandSyntaxException){
			$output = $this->sender->takeOutput();
			return [self::REJECTED, "invalid usage of /$label" . ($output === [] ? "" : ": " . implode(" ", $output)), null, []];
		}catch(\Throwable $e){
			$this->sender->takeOutput();
			$this->plugin->getLogger()->logException($e);
			return [self::QUARANTINED, "/$label threw " . $e::class . " while running", null, []];
		}
		if($this->sender->wasRefused()){
			return [self::REJECTED, "/$label refused the Tebex sender: " . implode(" ", $this->sender->takeOutput()), null, []];
		}
		return [self::EXECUTED, $line, $player, $this->sender->takeOutput()];
	}

	/**
	 * Fills in the player placeholders. Returns null if the command or a value it uses could break out of its
	 * argument, which matters because offline stores accept any username at checkout.
	 */
	public static function render(string $template, string $name, string $uuid) : ?string{
		$line = ltrim(trim($template), "/");
		if($line === "" || preg_match('/[\x00-\x1F\x7F]/', $line) === 1){
			return null;
		}
		$valid = true;
		$line = preg_replace_callback('/"\{(name|player|username|id|uuid)\}"|\{(name|player|username|id|uuid)\}/', static function(array $match) use ($name, $uuid, &$valid) : string{
			$quoted = $match[1] !== "";
			$key = $quoted ? $match[1] : $match[2] ?? "";
			if($key === "id" || $key === "uuid"){
				$valid = $valid && preg_match('/^[0-9A-Za-z-]{0,64}$/D', $uuid) === 1;
				return $quoted ? "\"$uuid\"" : $uuid;
			}
			$valid = $valid && strlen($name) <= 32 && preg_match('/^[A-Za-z0-9_.*]+(?: [A-Za-z0-9_.*]+)*$/D', $name) === 1;
			return $quoted || str_contains($name, " ") ? "\"$name\"" : $name;
		}, $line);
		return $valid ? $line : null;
	}
}
