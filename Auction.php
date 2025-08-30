<?php

/*
__PocketMine Plugin__
name=Auction
description=A simple auction plugin
version=1.0
author=MineDg
class=Auction
apiversion=12.1
*/

class Auction implements Plugin{
	public $api;
	public $config;
	public $auctions = [];
	public $auctionsID = 0;
	public $history = [];

	public function __construct(ServerAPI $api, $server = false){
		$this->api = $api;
	}

	public function init(){
		$this->config = new Config($this->api->plugin->configPath($this)."config.properties", CONFIG_PROPERTIES, ["currency" => "i"]);
		$this->api->console->register("ah", "Auction commands", [$this, "command"]);
		$this->api->ban->cmdWhitelist("ah");
	}

	public function command($cmd, $params, $issuer, $alias){
		$output = "";
		switch($cmd){
			case "ah":
				if($issuer instanceof Player){
					if($params[0] === "list"){
						$this->showAuctions($issuer);
					}elseif($params[0] === "buy"){
						$this->buyItem($issuer, $params[1]);
					}elseif($params[0] === "sell"){
						$this->sellItem($issuer, $params[1], $params[2]);
					}elseif($params[0] === "search"){
						$this->searchItems($issuer, $params[1]);
					}elseif($params[0] === "view"){
						$this->viewPlayerItems($issuer, $params[1]);
					}elseif($params[0] === "history"){
						$this->showHistory($issuer);
					}else{
						$output .= "Usage: /ah <list|buy|sell|search|view|history>";
					}
				}else{
					$output .= "You must be a player to use this command!";
				}
				break;
		}
		return $output;
	}

	public function showAuctions(Player $player){
		$output = "";
		$output .= "Auctions:\n";
		foreach($this->auctions as $id => $auction){
			$output .= "$id. {$auction["name"]} - {$auction["price"]} {$this->config->get("currency")}\n";
		}
		$player->sendChat($output);
	}

	public function buyItem(Player $player, $id){
		if(!isset($this->auctions[$id])){
			$player->sendChat("Auction not found!");
			return;
		}
		$auction = $this->auctions[$id];
		if($auction["player"]->username !== $player->username){
			if($this->config->get("currency") === "i"){
				if($player->getMoney() < $auction["price"]){
					$player->sendChat("You don't have enough money!");
					return;
				}
				$player->reduceMoney($auction["price"]);
				$auction["player"]->addMoney($auction["price"]);
			}else{
				if($player->getDiamonds() < $auction["price"]){
					$player->sendChat("You don't have enough diamonds!");
					return;
				}
				$player->reduceDiamonds($auction["price"]);
				$auction["player"]->addDiamonds($auction["price"]);
			}
			$player->getInventory()->addItem($auction["item"]);
			unset($this->auctions[$id]);
			$this->history[] = ["player" => $player->username, "action" => "buy", "item" => $auction["item"]->getName(), "price" => $auction["price"]];
			$player->sendChat("You bought {$auction["item"]->getName()} for {$auction["price"]} {$this->config->get("currency")}!");
		}else{
			$player->sendChat("You can't buy your own auction!");
		}
	}

	public function sellItem(Player $player, $currency, $price){
		if($currency !== "i" and $currency !== "d"){
			$player->sendChat("Invalid currency! Use i or d.");
			return;
		}
		if($price <= 0){
			$player->sendChat("Invalid price! Price must be greater than 0.");
			return;
		}
		$item = $player->getInventory()->getItemInHand();
		if($item->getID() === 0){
			$player->sendChat("You must hold an item to sell!");
			return;
		}
		$this->auctionsID++;
		$this->auctions[$this->auctionsID] = ["player" => $player, "item" => $item, "price" => $price, "name" => $item->getName()];
		$player->getInventory()->removeItem($item);
		$player->sendChat("You put {$item->getName()} up for auction for $price $currency!");
		$this->history[] = ["player" => $player->username, "action" => "sell", "item" => $item->getName(), "price" => $price];
	}

	public function searchItems(Player $player, $input){
		$output = "";
		$output .= "Search results:\n";
		foreach($this->auctions as $id => $auction){
			if(strpos(strtolower($auction["name"]), strtolower($input)) !== false){
				$output .= "$id. {$auction["name"]} - {$auction["price"]} {$this->config->get("currency")}\n";
			}
		}
		$player->sendChat($output);
	}

	public function viewPlayerItems(Player $player, $username){
		$output = "";
		$output .= "Auctions by $username:\n";
		foreach($this->auctions as $id => $auction){
			if($auction["player"]->username === $username){
				$output .= "$id. {$auction["name"]} - {$auction["price"]} {$this->config->get("currency")}\n";
			}
		}
		$player->sendChat($output);
	}

	public function showHistory(Player $player){
		$output = "";
		$output .= "Transaction history:\n";
		foreach($this->history as $transaction){
			$output .= "Player {$transaction["player"]} {$transaction["action"]} {$transaction["item"]} for {$transaction["price"]} {$this->config->get("currency")}\n";
		}
		$player->sendChat($output);
	}

	public function __destruct(){}

}