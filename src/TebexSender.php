<?php

declare(strict_types=1);

namespace Trix\Tebex;

use quark\command\CommandSender;
use quark\lang\KnownTranslationKeys;
use quark\lang\Language;
use quark\lang\Translatable;
use quark\permission\PermissibleBase;
use quark\permission\PermissibleDelegateTrait;
use quark\Server;
use quark\utils\TextFormat;
use function count;
use function str_contains;
use function trim;
use const PHP_INT_MAX;

final class TebexSender implements CommandSender {
    use PermissibleDelegateTrait;

    // These translation keys identify commands that stopped before execution.
    private const array REFUSALS = [
        KnownTranslationKeys::QUARK_COMMAND_ERROR_PERMISSION,
        KnownTranslationKeys::QUARK_COMMAND_ERROR_PLAYERUSERONLY
    ];

    /** @var list<string> */
    private array $output = [];
    private bool $refused = false;

    /**
     * @param array<string, bool> $permissions
     */
    public function __construct(
        private Server $server,
        array $permissions
    ) {
        $this->perm = new PermissibleBase($permissions);
    }

    public function getServer(): Server {
        return $this->server;
    }

    public function getLanguage(): Language {
        return $this->server->getLanguage();
    }

    public function sendMessage(Translatable|string $message): void {
        if ($message instanceof Translatable) {
            foreach (self::REFUSALS as $key) {
                $this->refused = $this->refused || str_contains($message->getText(), $key);
            }
            $message = $this->getLanguage()->translate($message);
        }
        if (count($this->output) < 32) {
            $this->output[] = trim(TextFormat::clean($message));
        }
    }

    /**
     * Returns and clears the replies since the last call.
     *
     * @return list<string>
     */
    public function takeOutput(): array {
        $this->refused = false;
        $output = $this->output;
        $this->output = [];
        return $output;
    }

    /** Whether a command refused this sender since the last {@link takeOutput()}. */
    public function wasRefused(): bool {
        return $this->refused;
    }

    public function getName(): string {
        return "Tebex";
    }

    public function getScreenLineHeight(): int {
        return PHP_INT_MAX;
    }

    public function setScreenLineHeight(?int $height): void {
    }
}
