<?php

declare(strict_types=1);

namespace Trix\Tebex\command;

use quark\command\argument\EnumArgument;
use quark\command\argument\IntegerArgument;
use quark\command\Command;
use quark\command\CommandSender;
use quark\player\Player;
use quark\plugin\Plugin;
use quark\plugin\PluginOwned;
use quark\utils\TextFormat;
use Trix\Tebex\api\TebexException;
use Trix\Tebex\CommandQueue;
use Trix\Tebex\Store;
use Trix\Tebex\Tebex;
use function count;
use function date;
use function implode;
use function max;
use function time;

final class TebexCommand extends Command implements PluginOwned {
    public function __construct(private Tebex $plugin) {
        parent::__construct("tebex", "Manage the Tebex integration", null, ["tbx"]);
        $this->setPermission("tebex.admin");
        $this->syntax("info")->handler($this->info(...));
        $this->syntax("status")->handler($this->status(...));
        $this->syntax("refresh")->handler($this->refresh(...));
        $this->syntax("quarantine")->handler($this->quarantine(...));
        $this->syntax("resolve")
            ->registerArguments([new IntegerArgument("id", min: 1), new EnumArgument("outcome", ["delivered", "retry"])])
            ->handler($this->resolve(...));
    }

    public function getOwningPlugin(): Plugin {
        return $this->plugin;
    }

    private function info(CommandSender $sender): void {
        $this->plugin->fetchStore()->then(function (Store $store) use ($sender): void {
            $current = $this->plugin->getStore();
            $lines = [
                TextFormat::GREEN . "Connected to " . $store->name,
                "Webstore: " . $store->domain,
                "Server: $store->serverName (#$store->serverId)",
                "Account: #$store->accountId, " . ($store->currency !== "" ? $store->currency : "no currency"),
                "Game type: $store->gameType, players matched by " . match ($store->matchesByXuid()) {
                    true => "XUID",
                    false => "username",
                    null => TextFormat::RED . "unsupported game type"
                }
            ];
            if ($current !== null && $current->scope() !== $store->scope()) {
                $lines[] = TextFormat::YELLOW . "The secret now belongs to another server, restart to switch";
            }
            $this->reply($sender, implode("\n", $lines));
        }, function (\Throwable $e) use ($sender): void {
            $detail = $e instanceof TebexException ? $e->getMessage() : "Unexpected request failure";
            $this->reply($sender, TextFormat::RED . "Tebex connection failed: $detail");
        });
    }

    private function status(CommandSender $sender): void {
        $queue = $this->plugin->getCommandQueue();
        if ($queue === null) {
            $this->reply($sender, TextFormat::YELLOW . ($this->plugin->getApi() === null ? "No secret key is set" : "Not connected to Tebex yet"));
            return;
        }
        $status = $queue->status();
        $now = time();
        $state = match (true) {
            $status["suspended"] === true => TextFormat::RED . "paused, the secret key was refused",
            $status["polling"] === true => TextFormat::GREEN . "checking the queue",
            $status["failures"] > 0 => TextFormat::YELLOW . "retrying after {$status["failures"]} failed checks: {$status["lastError"]}",
            default => TextFormat::GREEN . "connected"
        };
        $lines = [
            "Tebex: $state",
            "Last check: " . ($status["lastPoll"] === 0 ? "never" : date("H:i:s", (int) $status["lastPoll"]))
                . ($status["nextPoll"] === 0 ? "" : ", next in " . max(0, (int) $status["nextPoll"] - $now) . "s"),
            "Due players: {$status["duePlayers"]}, commands in progress: {$status["active"]}, waiting to run: {$status["running"]}",
            "Unacknowledged: {$status["unacknowledged"]}, requests queued: {$status["requests"]}, journal writes: {$status["journalWrites"]}"
        ];
        $this->reply($sender, implode("\n", $lines));
    }

    private function refresh(CommandSender $sender): void {
        $queue = $this->plugin->getCommandQueue();
        if ($queue === null) {
            $this->reply($sender, $this->plugin->connect() ? "Connecting to Tebex" : TextFormat::YELLOW . "Not connected, check the console");
            return;
        }
        $queue->refresh()->then(
            fn(int $due) => $this->reply($sender, TextFormat::GREEN . "Checked the Tebex queue, $due players have commands due"),
            fn(\Throwable $e) => $this->reply($sender, TextFormat::YELLOW . (
                $e instanceof TebexException ? $e->getMessage() : "Could not refresh the Tebex queue"
            ))
        );
    }

    private function quarantine(CommandSender $sender): void {
        $queue = $this->connected($sender);
        $queue?->quarantined()->then(function (array $rows) use ($sender): void {
            if ($rows === []) {
                $this->reply($sender, TextFormat::GREEN . "No commands need review");
                return;
            }
            $lines = [TextFormat::YELLOW . count($rows) . " commands need review, settle each with /tebex resolve <id> <delivered|retry>"];
            foreach ($rows as [$id, $updated]) {
                $lines[] = "#$id " . date("Y-m-d H:i", $updated);
            }
            $this->reply($sender, implode("\n", $lines));
        }, fn() => $this->reply($sender, TextFormat::RED . "Could not read the delivery journal"));
    }

    private function resolve(CommandSender $sender, int $id, string $outcome): void {
        $queue = $this->connected($sender);
        $queue?->resolve($id, $outcome === "delivered")->then(function (bool $resolved) use ($sender, $id, $outcome): void {
            $this->reply($sender, match (true) {
                !$resolved => TextFormat::RED . "Command #$id is not quarantined",
                $outcome === "delivered" => TextFormat::GREEN . "Command #$id is marked as delivered and will be acknowledged",
                default => TextFormat::GREEN . "Command #$id will run again on the next queue check"
            });
        }, fn() => $this->reply($sender, TextFormat::RED . "Could not update the delivery journal"));
    }

    private function connected(CommandSender $sender): ?CommandQueue {
        $queue = $this->plugin->getCommandQueue();
        if ($queue === null) {
            $this->reply($sender, TextFormat::YELLOW . "Not connected to Tebex yet");
        }
        return $queue;
    }

    private function reply(CommandSender $sender, string $message): void {
        if (!$sender instanceof Player || $sender->isConnected()) {
            $sender->sendMessage($message);
        }
    }
}
