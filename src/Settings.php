<?php

declare(strict_types=1);

namespace Trix\Tebex;

use Trix\Tebex\api\TebexApi;
use function array_fill_keys;
use function array_is_list;
use function getenv;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function parse_url;
use function preg_match;
use function rtrim;
use function str_starts_with;
use function trim;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

final readonly class Settings{
	/**
	 * @param list<string> $senderPermissions
	 */
	public function __construct(
		#[\SensitiveParameter] public string $secret,
		public string $buyMessage,
		public int $commandsPerTick,
		public array $senderPermissions,
		public string $database,
		public bool $debug,
		public string $apiUrl,
		public bool $allowHttp
	){}

	/**
	 * @param array<mixed> $config
	 * @throws \InvalidArgumentException
	 */
	public static function parse(array $config, string $dataFolder) : self{
		$secret = getenv("TEBEX_SECRET");
		$secret = is_string($secret) && trim($secret) !== "" ? trim($secret) : self::string($config, "secret", "");

		$perTick = $config["commands-per-tick"] ?? 50;
		if(!is_int($perTick) || $perTick < 1 || $perTick > 1000){
			throw new \InvalidArgumentException("commands-per-tick must be between 1 and 1000");
		}
		$permissions = $config["sender-permissions"] ?? ["quark.group.operator"];
		if(!is_array($permissions) || !array_is_list($permissions)){
			throw new \InvalidArgumentException("sender-permissions must be a list of permission names");
		}
		foreach($permissions as $permission){
			if(!is_string($permission) || $permission === ""){
				throw new \InvalidArgumentException("sender-permissions must be a list of permission names");
			}
		}
		$debug = $config["debug"] ?? false;
		if(!is_bool($debug)){
			throw new \InvalidArgumentException("debug must be true or false");
		}
		$database = self::string($config, "database", "journal.sqlite");
		if($database === ""){
			throw new \InvalidArgumentException("database must not be empty");
		}
		if(!str_starts_with($database, "/") && preg_match('/^[A-Za-z]:[\/\\\\]/', $database) !== 1){
			$database = $dataFolder . $database;
		}

		// Only for testing against a local fake of the API, plain HTTP is never allowed to remote hosts.
		$apiUrl = getenv("TEBEX_API_URL");
		$apiUrl = is_string($apiUrl) && $apiUrl !== "" ? rtrim($apiUrl, "/") : TebexApi::BASE_URL;
		$scheme = parse_url($apiUrl, PHP_URL_SCHEME);
		$host = parse_url($apiUrl, PHP_URL_HOST);
		$allowHttp = $scheme === "http" && in_array($host, ["127.0.0.1", "localhost", "[::1]"], true);
		if($scheme !== "https" && !$allowHttp){
			throw new \InvalidArgumentException("TEBEX_API_URL must use https");
		}

		return new self(
			$secret,
			self::string($config, "buy-message", "Support the server at {url}"),
			$perTick,
			$permissions,
			$database,
			$debug,
			$apiUrl,
			$allowHttp
		);
	}

	/**
	 * @return array<string, bool>
	 */
	public function senderPermissionMap() : array{
		return array_fill_keys($this->senderPermissions, true);
	}

	/**
	 * @param array<mixed> $config
	 */
	private static function string(array $config, string $key, string $default) : string{
		$value = $config[$key] ?? $default;
		if(!is_string($value)){
			throw new \InvalidArgumentException("$key must be a string");
		}
		return trim($value);
	}
}
