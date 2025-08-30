<?php
/*
__PocketMine Plugin__
name=MoreCrafts
description=Custom crafting system near workbench
version=1.0
author=MineDg
class=MoreCrafts
apiversion=12.1
*/

class MoreCrafts implements Plugin{
	public $api;
	public $crafts;

	public function __construct(ServerAPI $api, $s = false){
		$this->api = $api;
	}

	public function init(){
		$this->crafts = new Config($this->api->plugin->configPath($this)."crafts.yml", CONFIG_YAML, []);
		$this->api->console->register("craft", "<id|add|list|remove>", [$this, "handleCommand"]);
		$this->api->ban->cmdWhitelist("craft");
	}

	public function handleCommand($cmd, $args, $issuer, $alias){
		if (!($issuer instanceof Player)) {
            return "This command can only be used in-game.";
        }

		if(!isset($args[0])) return "Usage: /craft <id|add|list|remove>";

		switch(strtolower($args[0])){
			case "add":
				return $this->addCraft($args, $issuer);
			case "list":
				return $this->listCrafts();
			case "remove":
				return $this->removeCraft($args);
			default:
				return $this->doCraft($args[0], $issuer);
		}
	}

	public function isNearWorkbench(Player $player){
		$pos = $player->entity;
		$level = $pos->level;
		for($x = -2; $x <= 2; $x++){
			for($y = -2; $y <= 2; $y++){
				for($z = -2; $z <= 2; $z++){
					$block = $level->getBlock(new Vector3($pos->x + $x, $pos->y + $y, $pos->z + $z));
					if($block->getID() === 58){
						return true;
					}
				}
			}
		}
		return false;
	}

	public function parseItem($str){
		$parts = explode(":", $str);
		$id = (int)$parts[0];
		$meta = isset($parts[1]) ? (int)$parts[1] : 0;
		return [$id, $meta];
	}

	public function addCraft($args, Player $player){
		$line = implode(" ", array_slice($args, 1));
		$parts = preg_split("/\s*>\s*/", $line);
		if(count($parts) !== 2) return "Invalid format. Use: /craft add <input> > <output>";

		$inputStr = trim($parts[0]);
		$outputStr = trim($parts[1]);

		$inputParts = explode(" ", $inputStr);
		$outputParts = explode(" ", $outputStr);

		if(count($outputParts) < 2) return "Invalid output format.";

		$input = [];
		for($i = 0; $i < count($inputParts); $i += 2){
			if(!isset($inputParts[$i+1])) return "Invalid input format.";
			list($id, $meta) = $this->parseItem($inputParts[$i]);
			$count = (int)$inputParts[$i+1];
			$input[] = [$id, $meta, $count];
		}

		list($outId, $outMeta) = $this->parseItem($outputParts[0]);
		$outCount = (int)$outputParts[1];

		$all = $this->crafts->getAll();
		$nextId = 1;
		while(isset($all[$nextId])) $nextId++;

		$all[$nextId] = [
			"input" => $input,
			"output" => [$outId, $outMeta, $outCount]
		];

		$this->crafts->setAll($all);
		$this->crafts->save();

		return "Craft added.";
	}

	public function listCrafts(){
		$all = $this->crafts->getAll();
		if(count($all) === 0) return "No crafts defined.";

		$out = "Crafts:\n";
		foreach($all as $id => $data){
			$in = [];
			foreach($data["input"] as $i){
				$in[] = "{$i[0]}:{$i[1]} x{$i[2]}";
			}
			$out .= "$id: ".implode(", ", $in)." > {$data["output"][0]}:{$data["output"][1]} x{$data["output"][2]}\n";
		}
		return $out;
	}

	public function removeCraft($args){
		if(!isset($args[1])) return "Usage: /craft remove <id>";
		$id = (int)$args[1];
		if(!$this->crafts->exists($id)) return "Craft not found.";
		$this->crafts->remove($id);
		$this->crafts->save();
		return "Craft removed.";
	}

	public function doCraft($id, Player $player){
		if(!$this->isNearWorkbench($player)) return "You must be near a workbench.";

		$id = (int)$id;
		if(!$this->crafts->exists($id)) return "Craft not found.";

		$data = $this->crafts->get($id);
		$input = $data["input"];
		$output = $data["output"];

		$inv = $player->inventory;
		foreach($input as $req){
			$has = $inv->has($req[0], $req[1], $req[2]);
			if(!$has) return "You don't have enough of item {$req[0]}:{$req[1]} x{$req[2]}";
		}

		foreach($input as $req){
			$inv->removeItem($req[0], $req[1], $req[2]);
		}

		$item = BlockAPI::getItem($output[0], $output[1], $output[2]);
		$this->api->entity->drop($player->entity, $item);

		return "Crafted {$output[0]}:{$output[1]} x{$output[2]}";
	}

	public function __destruct(){}
}
