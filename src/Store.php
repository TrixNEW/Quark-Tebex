<?php

declare(strict_types=1);

namespace Trix\Tebex;

use Trix\Tebex\api\TebexException;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Webstore and server details from GET /information.
 */
final readonly class Store{
	public const string BEDROCK = "Minecraft (Bedrock)";
	public const string OFFLINE = "Minecraft (Offline/Geyser)";

	public function __construct(
		public int $accountId,
		public string $name,
		public string $domain,
		public string $currency,
		public string $gameType,
		public bool $onlineMode,
		public int $serverId,
		public string $serverName
	){}

	/**
	 * @throws TebexException
	 */
	public static function parse(mixed $data) : self{
		$account = is_array($data) ? $data["account"] ?? null : null;
		$server = is_array($data) ? $data["server"] ?? null : null;
		if(
			!is_array($account) || !is_array($server) ||
			!is_int($account["id"] ?? null) || !is_string($account["domain"] ?? null) || !is_string($account["game_type"] ?? null) ||
			!is_int($server["id"] ?? null) || !is_string($server["name"] ?? null)
		){
			throw new TebexException("Malformed store information from Tebex");
		}
		$currency = $account["currency"] ?? null;
		return new self(
			$account["id"],
			is_string($account["name"] ?? null) ? $account["name"] : "",
			$account["domain"],
			is_array($currency) && is_string($currency["iso_4217"] ?? null) ? $currency["iso_4217"] : "",
			$account["game_type"],
			is_bool($account["online_mode"] ?? null) && $account["online_mode"],
			$server["id"],
			$server["name"]
		);
	}

	/** Command IDs are tracked per Tebex account and server. */
	public function scope() : string{
		return $this->accountId . ":" . $this->serverId;
	}

	/** Bedrock stores identify players by XUID, offline/Geyser stores by username. */
	public function matchesByXuid() : ?bool{
		return match($this->gameType){
			self::BEDROCK => true,
			self::OFFLINE => false,
			default => null
		};
	}
}
