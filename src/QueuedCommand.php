<?php

declare(strict_types=1);

namespace Trix\Tebex;

use function is_array;
use function is_int;
use function is_string;
use function max;

final readonly class QueuedCommand {
    public function __construct(
        public int $id,
        public string $command,
        public bool $online,
        public int $playerId,
        public string $playerName,
        public string $playerUuid,
        public ?int $payment = null,
        public ?int $package = null,
        public int $delay = 0,
        public int $slots = 0
    ) {}

    /**
     * @param array{id: int, name: string, uuid: string}|null $player the due player of an online command
     */
    public static function parse(mixed $data, ?array $player = null): ?self {
        if (!is_array($data) || !is_int($data["id"] ?? null) || $data["id"] <= 0 || !is_string($data["command"] ?? null)) {
            return null;
        }
        $online = $player !== null;
        $player ??= self::parsePlayer($data["player"] ?? null);
        if ($player === null) return null;
        $conditions = is_array($data["conditions"] ?? null) ? $data["conditions"] : [];
        $delay = $conditions["delay"] ?? null;
        if (is_int($delay) && $delay > (PHP_INT_MAX >> 1)) return null;

        return new self(
            $data["id"],
            $data["command"],
            $online,
            $player["id"],
            $player["name"],
            $player["uuid"],
            is_int($data["payment"] ?? null) ? $data["payment"] : null,
            is_int($data["package"] ?? null) ? $data["package"] : null,
            is_int($delay) ? max(0, $delay) : 0,
            is_int($conditions["slots"] ?? null) ? max(0, $conditions["slots"]) : 0
        );
    }

    /**
     * @return array{id: int, name: string, uuid: string}|null
     */
    public static function parsePlayer(mixed $data): ?array {
        if (!is_array($data) || !is_int($data["id"] ?? null) || !is_string($data["name"] ?? null)) return null;
        $uuid = $data["uuid"] ?? null;
        return [
            "id" => $data["id"],
            "name" => $data["name"],
            "uuid" => is_int($uuid) || is_string($uuid) ? (string) $uuid : ""
        ];
    }
}
