<?php

declare(strict_types=1);

namespace Trix\Tebex\command;

use quark\command\Command;
use quark\command\CommandSender;
use quark\plugin\Plugin;
use quark\plugin\PluginOwned;
use quark\utils\TextFormat;
use Trix\Tebex\Tebex;
use function str_replace;

final class BuyCommand extends Command implements PluginOwned{
	public function __construct(
		private Tebex $plugin,
		private string $message
	){
		parent::__construct("buy", "Open the webstore", null, ["store"]);
		$this->setPermission("tebex.buy");
		$this->syntax()->handler($this->show(...));
	}

	public function getOwningPlugin() : Plugin{
		return $this->plugin;
	}

	private function show(CommandSender $sender) : void{
		$store = $this->plugin->getStore();
		if($store === null){
			$sender->sendMessage(TextFormat::RED . "The webstore is unavailable right now");
			return;
		}
		$sender->sendMessage(str_replace("{url}", $store->domain, $this->message));
	}
}
