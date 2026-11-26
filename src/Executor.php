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
use function count;
use function hrtime;
use function ltrim;
use function preg_match;
use function preg_replace_callback;
use function str_contains;
use function strlen;
use function trim;

final class Executor {
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
    /** @var array<int, true> */
    private array $blockedPayments = [];
    /** @phpstan-var TaskHandler<ClosureTask>|null */
    private ?TaskHandler $task = null;

    /**
     * @param \Closure(QueuedCommand): ?Player $player finds the online receiver of a command
     * @param \Closure(QueuedCommand, int, string): void $report receives the outcome of every command
     * @param \Closure(): void $drained called after each tick's commands ran
     */
    public function __construct(
        private Plugin $plugin,
        private SimpleCommandMap $commandMap,
        private TebexSender $sender,
        private int $perTick,
        private \Closure $player,
        private \Closure $report,
        private \Closure $drained
    ) {
        $this->queue = new \SplQueue();
    }

    public function enqueue(QueuedCommand $command): void {
        $this->queue->enqueue($command);
        $this->task ??= $this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask($this->drain(...)), 1);
    }

    public function queued(): int {
        return $this->queue->count();
    }

    public function blocker(QueuedCommand $command, ?Player $player): ?string {
        if (!$command->online) return null;
        if ($player === null || !$player->isConnected()) {
            return "player is offline";
        }
        if ($command->slots > 0) {
            $inventory = $player->getInventory();
            if ($inventory->getSize() - count($inventory->getContents()) < $command->slots) {
                return "needs $command->slots free inventory slots";
            }
        }
        return null;
    }

    public function drain(): void {
        $deadline = hrtime(true) + self::TICK_BUDGET_NS;
        for ($i = 0; $i < $this->perTick && !$this->queue->isEmpty() && ($i === 0 || hrtime(true) < $deadline); ++$i) {
            $command = $this->queue->dequeue();
            $payment = $command->payment;
            [$outcome, $detail, $player, $output] = $payment !== null && isset($this->blockedPayments[$payment])
                ? [self::BLOCKED, "an earlier purchase command is waiting", null, []]
                : $this->run($command);
            if ($payment !== null && $outcome !== self::EXECUTED) {
                $this->blockedPayments[$payment] = true;
            }
            ($this->report)($command, $outcome, $detail);
            if ($outcome === self::EXECUTED && TebexCommandExecutedEvent::hasHandlers()) {
                try {
                    (new TebexCommandExecutedEvent($this->plugin, $command, $player, $detail, $output))->call();
                } catch (\Throwable) {
                    $this->plugin->getLogger()->warning("A delivery listener failed for command #$command->id");
                }
            }
        }
        ($this->drained)();
        if ($this->queue->isEmpty()) {
            $this->task?->cancel();
            $this->task = null;
        }
    }

    public function resetPaymentBlocks(): void {
        $this->blockedPayments = [];
    }

    /**
     * @return list<QueuedCommand>
     */
    public function clear(): array {
        $this->task?->cancel();
        $this->task = null;
        $commands = [];
        while (!$this->queue->isEmpty()) {
            $commands[] = $this->queue->dequeue();
        }
        return $commands;
    }

    /**
     * @return array{int, string, ?Player, list<string>} outcome, command line or reason, receiver and replies
     */
    private function run(QueuedCommand $command): array {
        $player = $command->online ? ($this->player)($command) : null;
        if (($blocker = $this->blocker($command, $player)) !== null) {
            return [self::BLOCKED, $blocker, null, []];
        }
        $line = self::render($command->command, $player?->getName() ?? $command->playerName, $command->playerUuid);
        if ($line === null) {
            return [self::QUARANTINED, "unsafe command or placeholder value", null, []];
        }
        if (TebexCommandExecuteEvent::hasHandlers()) {
            $event = new TebexCommandExecuteEvent($this->plugin, $command, $player, $line);
            try {
                $event->call();
            } catch (\Throwable) {
                return [self::QUARANTINED, "a pre-execution listener failed", null, []];
            }
            if ($event->isCancelled()) {
                return [self::BLOCKED, "cancelled by a plugin", null, []];
            }
            $line = $event->getCommandLine();
        }

        $args = CommandStringHelper::parseQuoteAware($line);
        $label = array_shift($args) ?? "";
        $target = $this->commandMap->getCommand($label);
        if ($target === null) {
            return [self::REJECTED, "unknown command", null, []];
        }
        if ($target instanceof PluginOwned && !$target->getOwningPlugin()->isEnabled()) {
            return [self::REJECTED, "owning plugin is disabled", null, []];
        }
        if (!$target->testPermissionSilent($this->sender)) {
            return [self::REJECTED, "permission denied", null, []];
        }

        $this->sender->takeOutput();
        try {
            $target->dispatchLine($this->sender, $label, $args, $line);
        } catch (CommandInputException | InvalidCommandSyntaxException) {
            $this->sender->takeOutput();
            return [self::REJECTED, "invalid command usage", null, []];
        } catch (\Throwable) {
            $this->sender->takeOutput();
            return [self::QUARANTINED, "command failed during execution", null, []];
        }
        if ($this->sender->wasRefused()) {
            $this->sender->takeOutput();
            return [self::REJECTED, "command refused the Tebex sender", null, []];
        }
        return [self::EXECUTED, $line, $player, $this->sender->takeOutput()];
    }

    /**
     * Offline checkout names must fit inside a single command argument.
     */
    public static function render(string $template, string $name, string $uuid): ?string {
        $line = ltrim(trim($template), "/");
        if ($line === "" || preg_match('/[\x00-\x1F\x7F]/', $line) === 1) return null;
        $valid = true;
        $placeholders = '/"\{(name|player|username|id|uuid)\}"|\{(name|player|username|id|uuid)\}/';
        $line = preg_replace_callback($placeholders, static function (array $match) use ($name, $uuid, &$valid): string {
            $quoted = $match[1] !== "";
            $key = $quoted ? $match[1] : $match[2] ?? "";
            if ($key === "id" || $key === "uuid") {
                $valid = $valid && preg_match('/^[0-9A-Za-z-]{0,64}$/D', $uuid) === 1;
                return $quoted ? "\"$uuid\"" : $uuid;
            }
            $valid = $valid && strlen($name) <= 32 && preg_match('/^[A-Za-z0-9_.*]+(?: [A-Za-z0-9_.*]+)*$/D', $name) === 1;
            return $quoted || str_contains($name, " ") ? "\"$name\"" : $name;
        }, $line);
        return $valid ? $line : null;
    }
}
