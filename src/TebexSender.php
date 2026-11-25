<?php

declare(strict_types=1);

namespace Trix\Tebex;

use quark\command\CommandSender;
use quark\lang\Language;
use quark\lang\Translatable;
use quark\permission\PermissibleBase;
use quark\permission\PermissibleDelegateTrait;
use quark\Server;
use quark\utils\TextFormat;
use function array_splice;
use function count;
use function trim;
use const PHP_INT_MAX;

/**
 * Runs purchase commands with only the permissions it was given, and keeps what commands reply.
 */
final class TebexSender implements CommandSender{
	use PermissibleDelegateTrait;

	/** @var list<string> */
	private array $output = [];

	/**
	 * @param array<string, bool> $permissions
	 */
	public function __construct(
		private Server $server,
		array $permissions
	){
		$this->perm = new PermissibleBase($permissions);
	}

	public function getServer() : Server{
		return $this->server;
	}

	public function getLanguage() : Language{
		return $this->server->getLanguage();
	}

	public function sendMessage(Translatable|string $message) : void{
		if($message instanceof Translatable){
			$message = $this->getLanguage()->translate($message);
		}
		if(count($this->output) < 32){
			$this->output[] = trim(TextFormat::clean($message));
		}
	}

	/**
	 * Returns and clears the replies since the last call.
	 *
	 * @return list<string>
	 */
	public function takeOutput() : array{
		return array_splice($this->output, 0);
	}

	public function getName() : string{
		return "Tebex";
	}

	public function getScreenLineHeight() : int{
		return PHP_INT_MAX;
	}

	public function setScreenLineHeight(?int $height) : void{
	}
}
