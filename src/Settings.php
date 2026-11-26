<?php

declare(strict_types=1);

namespace Trix\Tebex;

use Trix\Tebex\api\TebexApi;
use function array_fill_keys;
use function array_is_list;
use function getenv;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function parse_url;
use function preg_match;
use function rtrim;
use function str_starts_with;
use function trim;

final readonly class Settings {
    /**
     * @param list<string> $senderPermissions
     */
    public function __construct(
        #[\SensitiveParameter] public string $secret,
        public string $buyMessage,
        public int $commandsPerTick,
        public array $senderPermissions,
        public string $database,
        public string $apiUrl,
        public bool $allowHttp
    ) {}

    /**
     * @param array<mixed> $config
     * @throws \InvalidArgumentException
     */
    public static function parse(#[\SensitiveParameter] array $config, string $dataFolder): self {
        $secret = getenv("TEBEX_SECRET");
        $secret = $secret === false ? "" : trim($secret);
        if ($secret === "") {
            $secret = self::string($config, "secret", "");
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $secret) === 1) {
            throw new \InvalidArgumentException("secret must be a single line");
        }

        $perTick = $config["commands-per-tick"] ?? 50;
        if (!is_int($perTick) || $perTick < 1 || $perTick > 1000) {
            throw new \InvalidArgumentException("commands-per-tick must be between 1 and 1000");
        }
        $permissions = $config["sender-permissions"] ?? ["quark.group.operator"];
        if (!is_array($permissions) || !array_is_list($permissions)) {
            throw new \InvalidArgumentException("sender-permissions must be a list of permission names");
        }
        foreach ($permissions as $permission) {
            if (!is_string($permission) || $permission === "") {
                throw new \InvalidArgumentException("sender-permissions must be a list of permission names");
            }
        }
        $database = self::string($config, "database", "journal.sqlite");
        if ($database === "") {
            throw new \InvalidArgumentException("database must not be empty");
        }
        if (!str_starts_with($database, "/") && preg_match('/^[A-Za-z]:[\/\\\\]/', $database) !== 1) {
            $database = $dataFolder . $database;
        }

        // Integration tests use a loopback HTTP server.
        $apiUrl = getenv("TEBEX_API_URL");
        $apiUrl = is_string($apiUrl) && $apiUrl !== "" ? rtrim($apiUrl, "/") : TebexApi::BASE_URL;
        $parts = parse_url($apiUrl);
        $scheme = $parts["scheme"] ?? null;
        $host = $parts["host"] ?? null;
        $allowHttp = $scheme === "http" && in_array($host, ["127.0.0.1", "localhost", "[::1]"], true);
        if ($host === null || isset($parts["user"]) || isset($parts["query"]) || isset($parts["fragment"])) {
            throw new \InvalidArgumentException("TEBEX_API_URL must be a base URL");
        }
        if ($scheme !== "https" && !$allowHttp) {
            throw new \InvalidArgumentException("TEBEX_API_URL must use HTTPS or loopback HTTP");
        }

        return new self(
            $secret,
            self::string($config, "buy-message", "Support the server at {url}"),
            $perTick,
            $permissions,
            $database,
            $apiUrl,
            $allowHttp
        );
    }

    /**
     * @return array<string, bool>
     */
    public function senderPermissionMap(): array {
        return array_fill_keys($this->senderPermissions, true);
    }

    /**
     * @param array<mixed> $config
     */
    private static function string(#[\SensitiveParameter] array $config, string $key, string $default): string {
        $value = $config[$key] ?? $default;
        if (!is_string($value)) {
            throw new \InvalidArgumentException("$key must be a string");
        }
        return trim($value);
    }
}
