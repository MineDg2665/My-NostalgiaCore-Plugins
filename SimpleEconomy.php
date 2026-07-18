<?php

/*
__PocketMine Plugin__
name=SimpleEconomy
description=Simple economy plugin with ChestShop
version=2.0
author=MineDg
class=SimpleEconomy
apiversion=12.1,12.2
*/

class SimpleEconomy implements Plugin{
    private $api;
    private $db;
    private $currency = "$";
    private $config;
    private $balances = [];

    public function __construct(ServerAPI $api, $server = false){
        $this->api = $api;
    }

    public function init(){
        $path = $this->api->plugin->configPath($this);

        $defaultConfig = [
            "currency" => "$",
            "starting-balance" => 1000,
            "transfer-commission" => 0,
            "min-transfer" => 10,
            "max-transfer" => 10000,
            "ore-rewards" => [
                "gold_ore" => [8, 20],
                "iron_ore" => [5, 15],
                "coal_ore" => [3, 10],
                "lapis_ore" => [10, 25],
                "diamond_ore" => [20, 50],
                "redstone_ore" => [6, 18],
                "glowing_redstone_ore" => [6, 18]
            ],
            "peaceful-mob-rewards" => [
                "chicken" => [2, 6],
                "cow" => [3, 8],
                "pig" => [2, 5],
                "sheep" => [3, 7]
            ],
            "hostile-mob-rewards" => [
                "zombie" => [5, 15],
                "creeper" => [8, 20],
                "skeleton" => [6, 18],
                "spider" => [4, 12],
                "pigman" => [7, 16]
            ]
        ];

        $configFile = $path . "config.yml";
        if(!file_exists($configFile)){
            $this->api->plugin->writeYAML($configFile, $defaultConfig);
        }
        $this->config = new Config($configFile, CONFIG_YAML, $defaultConfig);

        $this->currency = $this->config->get("currency");

        $dbFile = $path . "balances.sqlite3";
        $this->db = new SQLite3($dbFile);
        $this->db->exec("CREATE TABLE IF NOT EXISTS balances (username TEXT PRIMARY KEY, balance INTEGER DEFAULT 0);");
        $this->db->exec("CREATE TABLE IF NOT EXISTS history (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT, receiver TEXT, amount INTEGER, time INTEGER);");
        $this->db->exec("DROP TABLE IF EXISTS chestshops;");
        $this->db->exec("CREATE TABLE IF NOT EXISTS chestshops (id INTEGER PRIMARY KEY AUTOINCREMENT, level TEXT, x INTEGER, y INTEGER, z INTEGER, type TEXT, price INTEGER, item_id INTEGER, item_meta INTEGER, item_amount INTEGER, owner TEXT);");

        $this->loadBalances();

        $this->api->console->register("wallet", "Wallet commands", [$this, "commandHandler"]);
        $this->api->console->register("balance", "Show balance command", [$this, "balanceCommand"]);
        $this->api->console->register("money", "Show balance command", [$this, "balanceCommand"]);
        $this->api->console->register("pay", "Pay another player", [$this, "payCommand"]);
        $this->api->console->register("balancetop", "Show top 5 richest players", [$this, "balanceTopCommand"]);

        $this->api->addHandler("player.block.break", [$this, "onBlockBreak"], 15);
        $this->api->addHandler("entity.death", [$this, "onEntityDeath"], 15);
        $this->api->addHandler("player.join", [$this, "onPlayerJoin"], 15);
        $this->api->addHandler("player.block.touch", [$this, "onBlockTouch"], 15);
        $this->api->addHandler("tile.update", [$this, "onTileUpdate"], 15);

        $this->api->ban->cmdWhitelist("wallet");
        $this->api->ban->cmdWhitelist("balance");
        $this->api->ban->cmdWhitelist("money");
        $this->api->ban->cmdWhitelist("pay");
        $this->api->ban->cmdWhitelist("balancetop");
    }

    private function loadBalances(){
        $result = $this->db->query("SELECT username, balance FROM balances;");
        if($result === false) return;
        while($row = $result->fetchArray(SQLITE3_ASSOC)){
            $this->balances[strtolower($row["username"])] = (int)$row["balance"];
        }
    }

    private function saveBalance($username){
        $username = strtolower($username);
        $balance = isset($this->balances[$username]) ? $this->balances[$username] : 0;
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO balances (username, balance) VALUES (:username, :balance);");
        $stmt->bindValue(":username", $username, SQLITE3_TEXT);
        $stmt->bindValue(":balance", $balance, SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function addHistory($sender, $receiver, $amount){
        $stmt = $this->db->prepare("INSERT INTO history (sender, receiver, amount, time) VALUES (:sender, :receiver, :amount, :time);");
        $stmt->bindValue(":sender", strtolower($sender), SQLITE3_TEXT);
        $stmt->bindValue(":receiver", strtolower($receiver), SQLITE3_TEXT);
        $stmt->bindValue(":amount", $amount, SQLITE3_INTEGER);
        $stmt->bindValue(":time", time(), SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function getBalance($username){
        $username = strtolower($username);
        if(!isset($this->balances[$username])){
            $this->balances[$username] = 0;
            $this->saveBalance($username);
        }
        return $this->balances[$username];
    }

    private function setBalance($username, $amount){
        $username = strtolower($username);
        $amount = max(0, (int)$amount);
        $this->balances[$username] = $amount;
        $this->saveBalance($username);
    }

    private function addBalance($username, $amount){
        $username = strtolower($username);
        $amount = (int)$amount;
        if($amount <= 0) return false;
        $current = $this->getBalance($username);
        $this->balances[$username] = $current + $amount;
        $this->saveBalance($username);
        return true;
    }

    private function reduceBalance($username, $amount){
        $username = strtolower($username);
        $amount = (int)$amount;
        if($amount <= 0) return false;
        $current = $this->getBalance($username);
        if($current < $amount) return false;
        $this->balances[$username] = $current - $amount;
        $this->saveBalance($username);
        return true;
    }

    private function isOp($username){
        return $this->api->ban->isOp($username);
    }

    public function onPlayerJoin($data, $event){
        if(!($data instanceof Player)) return;
        $username = $data->username;
        $balance = $this->getBalance($username);
        if($balance <= 0){
            $starting = (int)$this->config->get("starting-balance", 1000);
            $this->setBalance($username, $starting);
            $data->sendChat("[SimpleEconomy] You received $starting {$this->currency} as starting balance.");
        }
    }

    private function getShopAt(Level $level, $x, $y, $z){
        $stmt = $this->db->prepare("SELECT * FROM chestshops WHERE x = :x AND y = :y AND z = :z AND level = :level LIMIT 1;");
        $stmt->bindValue(":x", (int)$x, SQLITE3_INTEGER);
        $stmt->bindValue(":y", (int)$y, SQLITE3_INTEGER);
        $stmt->bindValue(":z", (int)$z, SQLITE3_INTEGER);
        $stmt->bindValue(":level", $level->getName(), SQLITE3_TEXT);
        $result = $stmt->execute();
        if($result instanceof SQLite3Result){
            $row = $result->fetchArray(SQLITE3_ASSOC);
            return $row !== false ? $row : false;
        }
        return false;
    }

    private function removeShopAt(Level $level, $x, $y, $z){
        $stmt = $this->db->prepare("DELETE FROM chestshops WHERE x = :x AND y = :y AND z = :z AND level = :level;");
        $stmt->bindValue(":x", (int)$x, SQLITE3_INTEGER);
        $stmt->bindValue(":y", (int)$y, SQLITE3_INTEGER);
        $stmt->bindValue(":z", (int)$z, SQLITE3_INTEGER);
        $stmt->bindValue(":level", $level->getName(), SQLITE3_TEXT);
        $stmt->execute();
    }

    private function parseItem($line){
        $parts = explode(":", $line);
        if(!isset($parts[0])) return false;
        $i = BlockAPI::fromString($parts[0]);
        if($i->getID() === AIR) return false;
        if(isset($parts[2])){
            return BlockAPI::getItem((int)$i->getID(), (int)$parts[1], (int)$parts[2]);
        }elseif(isset($parts[1])){
            return BlockAPI::getItem((int)$i->getID(), 0, (int)$parts[1]);
        }
        return $i;
    }

    private function countPlayerItems(Player $player, $itemId, $itemMeta){
        $count = 0;
        foreach($player->inventory as $slot){
            if($slot->getID() === $itemId && $slot->getMetadata() === $itemMeta){
                $count += $slot->count;
            }
        }
        return $count;
    }

    private function chestHasItems(Tile $tile, $itemId, $itemMeta, $amount){
        $total = $amount;
        if(isset($tile->data["Items"])){
            foreach($tile->data["Items"] as $s){
                if((int)$s["id"] === $itemId && (int)$s["Damage"] === $itemMeta){
                    $total -= (int)$s["Count"];
                    if($total <= 0) return true;
                }
            }
        }
        if($tile->isPaired()){
            $pair = $tile->getPair();
            if($pair && isset($pair->data["Items"])){
                foreach($pair->data["Items"] as $s){
                    if((int)$s["id"] === $itemId && (int)$s["Damage"] === $itemMeta){
                        $total -= (int)$s["Count"];
                        if($total <= 0) return true;
                    }
                }
            }
        }
        return false;
    }

    private function chestHasSpace(Tile $tile, $itemId, $itemMeta, $amount){
        $tiles = [$tile];
        if($tile->isPaired()){
            $pair = $tile->getPair();
            if($pair) $tiles[] = $pair;
        }
        $maxStack = BlockAPI::getItem($itemId, $itemMeta)->getMaxStackSize();
        foreach($tiles as $ct){
            $used = isset($ct->data["Items"]) ? count($ct->data["Items"]) : 0;
            $free = CHEST_SLOTS - $used;
            $space = $free * $maxStack;
            if(isset($ct->data["Items"])){
                foreach($ct->data["Items"] as $s){
                    if((int)$s["id"] === $itemId && (int)$s["Damage"] === $itemMeta){
                        $space += $maxStack - (int)$s["Count"];
                    }
                }
            }
            $amount -= min($amount, $space);
            if($amount <= 0) return true;
        }
        return false;
    }

    private function removeItemsFromChest(Tile $tile, $itemId, $itemMeta, $amount){
        $tiles = [$tile];
        if($tile->isPaired()){
            $pair = $tile->getPair();
            if($pair) $tiles[] = $pair;
        }
        $remaining = $amount;
        foreach($tiles as $ct){
            if(!isset($ct->data["Items"])) continue;
            foreach($ct->data["Items"] as $index => $s){
                if($remaining <= 0) break;
                if((int)$s["id"] === $itemId && (int)$s["Damage"] === $itemMeta){
                    $stackCount = (int)$s["Count"];
                    if($stackCount <= $remaining){
                        $remaining -= $stackCount;
                        $ct->setSlot((int)$s["Slot"], BlockAPI::getItem(AIR, 0, 0), false);
                    }else{
                        $newCount = $stackCount - $remaining;
                        $ct->setSlot((int)$s["Slot"], BlockAPI::getItem($itemId, $itemMeta, $newCount), false);
                        $remaining = 0;
                        break;
                    }
                }
            }
        }
        return $amount - $remaining;
    }

    private function addItemsToChest(Tile $tile, $itemId, $itemMeta, $amount){
        $tiles = [$tile];
        if($tile->isPaired()){
            $pair = $tile->getPair();
            if($pair) $tiles[] = $pair;
        }
        $remaining = $amount;
        $maxStack = BlockAPI::getItem($itemId, $itemMeta)->getMaxStackSize();
        foreach($tiles as $ct){
            if(!isset($ct->data["Items"])) $ct->data["Items"] = [];
            foreach($ct->data["Items"] as $index => &$s){
                if($remaining <= 0) break;
                if((int)$s["id"] === $itemId && (int)$s["Damage"] === $itemMeta){
                    $space = $maxStack - (int)$s["Count"];
                    if($space > 0){
                        $add = min($remaining, $space);
                        $s["Count"] += $add;
                        $remaining -= $add;
                    }
                }
            }
            unset($s);
            if($remaining > 0){
                for($slot = 0; $slot < CHEST_SLOTS && $remaining > 0; ++$slot){
                    $found = false;
                    foreach($ct->data["Items"] as $sd){
                        if((int)$sd["Slot"] === $slot){
                            $found = true;
                            break;
                        }
                    }
                    if(!$found){
                        $add = min($remaining, $maxStack);
                        $ct->data["Items"][] = [
                            "Count" => $add,
                            "Slot" => $slot,
                            "id" => $itemId,
                            "Damage" => $itemMeta
                        ];
                        $remaining -= $add;
                    }
                }
            }
        }
    }

    private function handleShopInteraction(Player $player, Block $signBlock, $shop, Level $level){
        $shopType = $shop["type"];
        $price = (int)$shop["price"];
        $itemId = (int)$shop["item_id"];
        $itemMeta = (int)$shop["item_meta"];
        $itemAmount = (int)$shop["item_amount"];
        $owner = $shop["owner"];

        $chestTile = $this->api->tile->get(new Position($signBlock->x, $signBlock->y - 1, $signBlock->z, $level));
        if(!($chestTile instanceof Tile) || $chestTile->class !== TILE_CHEST){
            $player->sendChat("[SimpleEconomy] The shop chest is missing.");
            return false;
        }

        $item = BlockAPI::getItem($itemId, $itemMeta, $itemAmount);
        $buyerName = strtolower($player->username);
        $ownerLower = strtolower($owner);

        if($shopType === "buy"){
            $buyerBalance = $this->getBalance($buyerName);
            if($buyerBalance < $price){
                $player->sendChat("[SimpleEconomy] You don't have enough money.");
                return false;
            }
            if(!$this->chestHasItems($chestTile, $itemId, $itemMeta, $itemAmount)){
                $player->sendChat("[SimpleEconomy] The shop chest does not have enough items.");
                return false;
            }
            if(!$player->hasSpace($itemId, $itemMeta, $itemAmount)){
                $player->sendChat("[SimpleEconomy] You don't have enough inventory space.");
                return false;
            }
            $this->reduceBalance($buyerName, $price);
            $this->addBalance($ownerLower, $price);
            $this->removeItemsFromChest($chestTile, $itemId, $itemMeta, $itemAmount);
            $player->addItem($itemId, $itemMeta, $itemAmount);
            $player->sendChat("[SimpleEconomy] You bought {$itemAmount}x {$item->getName()} for {$price} {$this->currency}.");

            $ownerPlayer = $this->api->player->get($owner, false, false);
            if($ownerPlayer instanceof Player){
                $ownerPlayer->sendChat("[SimpleEconomy] {$player->username} bought {$itemAmount}x {$item->getName()} from your shop for {$price} {$this->currency}.");
            }
        }elseif($shopType === "sell"){
            $ownerBalance = $this->getBalance($ownerLower);
            if($ownerBalance < $price){
                $player->sendChat("[SimpleEconomy] The shop owner does not have enough money.");
                return false;
            }
            $hasItems = $this->countPlayerItems($player, $itemId, $itemMeta);
            if($hasItems < $itemAmount){
                $player->sendChat("[SimpleEconomy] You don't have enough items to sell.");
                return false;
            }
            if(!$this->chestHasSpace($chestTile, $itemId, $itemMeta, $itemAmount)){
                $player->sendChat("[SimpleEconomy] The shop chest is full.");
                return false;
            }
            $this->reduceBalance($ownerLower, $price);
            $this->addBalance($buyerName, $price);
            $player->removeItem($itemId, $itemMeta, $itemAmount);
            $this->addItemsToChest($chestTile, $itemId, $itemMeta, $itemAmount);
            $player->sendChat("[SimpleEconomy] You sold {$itemAmount}x {$item->getName()} for {$price} {$this->currency}.");

            $ownerPlayer = $this->api->player->get($owner, false, false);
            if($ownerPlayer instanceof Player){
                $ownerPlayer->sendChat("[SimpleEconomy] {$player->username} sold {$itemAmount}x {$item->getName()} to your shop for {$price} {$this->currency}.");
            }
        }
        return false;
    }

    public function onBlockTouch($data, $event){
        $player = $data["player"];
        if(!($player instanceof Player)) return;
        $target = $data["target"];
        if($target === null) return;

        if($target->getID() === CHEST && $data["type"] === "break"){
            $shop = $this->getShopAt($target->level, $target->x, $target->y + 1, $target->z);
            if($shop !== false){
                $player->sendChat("[SimpleEconomy] You cannot break a chest that is linked to a ChestShop.");
                return false;
            }
            $tile = $this->api->tile->get(new Position($target->x, $target->y, $target->z, $target->level));
            if($tile instanceof Tile && $tile->class === TILE_CHEST && $tile->isPaired()){
                $pair = $tile->getPair();
                if($pair){
                    $shop2 = $this->getShopAt($target->level, $pair->x, $pair->y + 1, $pair->z);
                    if($shop2 !== false){
                        $player->sendChat("[SimpleEconomy] You cannot break a chest that is linked to a ChestShop.");
                        return false;
                    }
                }
            }
            return;
        }

        if($target->getID() !== WALL_SIGN) return;

        $level = $target->level;
        $shop = $this->getShopAt($level, $target->x, $target->y, $target->z);

        if($shop === false) return;

        if(($player->gamemode & 0x01) === 0x01){
            $player->sendChat("[SimpleEconomy] You must be in survival mode to interact with ChestShop.");
            return false;
        }

        $owner = $shop["owner"];

        if($data["type"] === "break"){
            if(strtolower($player->username) !== strtolower($owner) && !$this->isOp($player->username)){
                $player->sendChat("[SimpleEconomy] You cannot break another player's ChestShop.");
                return false;
            }
            $this->removeShopAt($level, $target->x, $target->y, $target->z);
            $player->sendChat("[SimpleEconomy] ChestShop removed.");
            return;
        }

        if(strtolower($player->username) === strtolower($owner)){
            $player->sendChat("[SimpleEconomy] You cannot trade with your own ChestShop.");
            return false;
        }

        return $this->handleShopInteraction($player, $target, $shop, $level);
    }

    public function onBlockBreak($data, $event){
        $player = $data["player"];
        if(!($player instanceof Player)) return;
        $block = $data["target"];

        if($block->getID() === WALL_SIGN){
            $shop = $this->getShopAt($block->level, $block->x, $block->y, $block->z);
            if($shop !== false && strtolower($player->username) !== strtolower($shop["owner"]) && !$this->isOp($player->username)){
                return false;
            }
            return;
        }

        $oreRewards = $this->config->get("ore-rewards", []);
        $blockId = $block->getID();
        $oreName = self::getOreName($blockId);
        if($oreName !== null && isset($oreRewards[$oreName])){
            $range = $oreRewards[$oreName];
            $min = (int)$range[0];
            $max = (int)$range[1];
            $amount = mt_rand($min, $max);
            $this->addBalance(strtolower($player->username), $amount);
            $player->sendChat("[SimpleEconomy] You received {$amount} {$this->currency}.");
        }
    }

    public function onEntityDeath($data, $event){
        $entity = $data["entity"];
        $cause = $data["cause"];

        if(!is_numeric($cause)) return;
        $killerEntity = $this->api->entity->get((int)$cause);
        if(!($killerEntity instanceof Entity) || !$killerEntity->isPlayer()) return;
        $killer = $killerEntity->player;
        if(!($killer instanceof Player)) return;

        $entityId = $entity->type;

        $peacefulMobName = self::getPeacefulMobName($entityId);
        if($peacefulMobName !== null){
            $peacefulRewards = $this->config->get("peaceful-mob-rewards", []);
            if(isset($peacefulRewards[$peacefulMobName])){
                $range = $peacefulRewards[$peacefulMobName];
                $min = (int)$range[0];
                $max = (int)$range[1];
                $amount = mt_rand($min, $max);
                $this->addBalance(strtolower($killer->username), $amount);
                $killer->sendChat("[SimpleEconomy] You received {$amount} {$this->currency}.");
                return;
            }
        }

        $hostileMobName = self::getHostileMobName($entityId);
        if($hostileMobName !== null){
            $hostileRewards = $this->config->get("hostile-mob-rewards", []);
            if(isset($hostileRewards[$hostileMobName])){
                $range = $hostileRewards[$hostileMobName];
                $min = (int)$range[0];
                $max = (int)$range[1];
                $amount = mt_rand($min, $max);
                $this->addBalance(strtolower($killer->username), $amount);
                $killer->sendChat("[SimpleEconomy] You received {$amount} {$this->currency}.");
            }
        }
    }

    public function onTileUpdate($data, $event){
        $tile = $data;
        if(!($tile instanceof Tile)) return;
        if($tile->class !== TILE_SIGN) return;

        $text1 = trim($tile->data["Text1"] ?? "");
        if($text1 !== "Buy" && $text1 !== "Sell") return;

        $creator = $tile->data["creator"] ?? "";
        if($creator === "") return;

        $player = $this->api->player->get($creator, false, false);
        if(!($player instanceof Player)) return;

        $rawprice = trim($tile->data["Text2"] ?? "");
        $itemLine = trim($tile->data["Text3"] ?? "");

        $price = (int)$rawprice;
        if($price <= 0){
            $player->sendChat("[SimpleEconomy] Invalid price on line 2. Must be a positive number.");
            return;
        }

        $item = $this->parseItem($itemLine);
        if($item === false){
            $player->sendChat("[SimpleEconomy] Invalid item format on line 3. Use id:meta:amount or name:amount.");
            return;
        }

        $blockBelow = $tile->level->getBlock(new Position($tile->x, $tile->y - 1, $tile->z, $tile->level));
        if($blockBelow->getID() !== CHEST){
            $player->sendChat("[SimpleEconomy] You must place the sign on top of a chest.");
            return;
        }

        $shop = $this->getShopAt($tile->level, $tile->x, $tile->y, $tile->z);
        if($shop !== false){
            $player->sendChat("[SimpleEconomy] A ChestShop already exists here.");
            return;
        }

        $typeStr = strtolower($text1);

        $stmt = $this->db->prepare("INSERT INTO chestshops (level, x, y, z, type, price, item_id, item_meta, item_amount, owner) VALUES (:level, :x, :y, :z, :type, :price, :item_id, :item_meta, :item_amount, :owner);");
        $stmt->bindValue(":level", $tile->level->getName(), SQLITE3_TEXT);
        $stmt->bindValue(":x", $tile->x, SQLITE3_INTEGER);
        $stmt->bindValue(":y", $tile->y, SQLITE3_INTEGER);
        $stmt->bindValue(":z", $tile->z, SQLITE3_INTEGER);
        $stmt->bindValue(":type", $typeStr, SQLITE3_TEXT);
        $stmt->bindValue(":price", $price, SQLITE3_INTEGER);
        $stmt->bindValue(":item_id", $item->getID(), SQLITE3_INTEGER);
        $stmt->bindValue(":item_meta", $item->getMetadata(), SQLITE3_INTEGER);
        $stmt->bindValue(":item_amount", $item->count, SQLITE3_INTEGER);
        $stmt->bindValue(":owner", $player->username, SQLITE3_TEXT);
        $stmt->execute();

        $itemName = $item->getName();
        $tile->data["Text2"] = "{$item->count} {$itemName}";
        $tile->data["Text3"] = "P: {$price} {$this->currency}";
        $tile->data["Text4"] = $player->username;
        $this->api->tile->spawnToAll($tile);

        $player->sendChat("[SimpleEconomy] ChestShop created! Type: {$text1}, Price: {$price} {$this->currency}, Item: {$item->count}x {$itemName}");
    }

    public function commandHandler($cmd, $params, $issuer, $alias){
        if(count($params) < 1){
            return $this->walletHelp();
        }

        $sub = strtolower(array_shift($params));
        $isOp = ($issuer instanceof Player) ? $this->isOp($issuer->username) : true;

        switch($sub){
            case "setcurrency":
                if(!$isOp){
                    return "[SimpleEconomy] You do not have permission to use this command.";
                }
                if(count($params) < 1){
                    return "Usage: /wallet setcurrency <symbol>";
                }
                $symbol = $params[0];
                $this->currency = $symbol;
                $this->config->set("currency", $symbol);
                $this->config->save();
                return "[SimpleEconomy] Currency symbol set to '$symbol'.";

            case "history":
                if(count($params) < 1){
                    if($issuer instanceof Player){
                        return $this->getHistory($issuer->username);
                    }
                    return "Usage: /wallet history <player>";
                }
                $targetName = $params[0];
                if(!$isOp && $issuer instanceof Player && strtolower($issuer->username) !== strtolower($targetName)){
                    return "[SimpleEconomy] You can only view your own history.";
                }
                return $this->getHistory($targetName);

            case "help":
                return $this->walletHelp();

            case "give":
                if(!$isOp){
                    return "[SimpleEconomy] You do not have permission to use this command.";
                }
                if(count($params) < 2){
                    return "Usage: /wallet give <player> <amount>";
                }
                $playerName = $params[0];
                $amount = (int)$params[1];
                if($amount <= 0){
                    return "[SimpleEconomy] Amount must be positive.";
                }
                $this->addBalance($playerName, $amount);
                return "[SimpleEconomy] Gave {$amount} {$this->currency} to {$playerName}.";

            case "take":
                if(!$isOp){
                    return "[SimpleEconomy] You do not have permission to use this command.";
                }
                if(count($params) < 2){
                    return "Usage: /wallet take <player> <amount>";
                }
                $playerName = $params[0];
                $amount = (int)$params[1];
                if($amount <= 0){
                    return "[SimpleEconomy] Amount must be positive.";
                }
                if(!$this->reduceBalance($playerName, $amount)){
                    return "[SimpleEconomy] {$playerName} does not have enough funds.";
                }
                return "[SimpleEconomy] Took {$amount} {$this->currency} from {$playerName}.";

            default:
                return "Unknown subcommand. Use /wallet help";
        }
    }

    private function walletHelp(){
        $help = "SimpleEconomy commands:\n";
        $help .= "/wallet setcurrency <symbol> - Set currency symbol (OP only)\n";
        $help .= "/wallet history [player] - Show transfer history\n";
        $help .= "/wallet help - Show this help\n";
        $help .= "/wallet give <player> <amount> - Give money to player (OP only)\n";
        $help .= "/wallet take <player> <amount> - Take money from player (OP only)\n";
        $help .= "/balance or /money [player] - Show player balance\n";
        $help .= "/pay <player> <amount> - Pay money to another player\n";
        $help .= "/balancetop - Show top 5 richest players\n";
        return $help;
    }

    public function balanceCommand($cmd, $params, $issuer, $alias){
        $isOp = ($issuer instanceof Player) ? $this->isOp($issuer->username) : true;

        if(count($params) < 1){
            if($issuer instanceof Player){
                $balance = $this->getBalance($issuer->username);
                return "[SimpleEconomy] Your balance: {$balance} {$this->currency}.";
            }
            return "Usage: /{$cmd} <player>";
        }

        $targetName = $params[0];

        if(!$isOp && $issuer instanceof Player && strtolower($issuer->username) !== strtolower($targetName)){
            return "[SimpleEconomy] You can only view your own balance.";
        }

        $balance = $this->getBalance($targetName);
        return "[SimpleEconomy] {$targetName}'s balance: {$balance} {$this->currency}.";
    }

    public function payCommand($cmd, $params, $issuer, $alias){
        if(!($issuer instanceof Player)){
            return "This command can only be used in-game.";
        }
        if(count($params) < 2){
            return "Usage: /pay <player> <amount>";
        }
        $sender = $issuer->username;
        $receiver = $params[0];
        $amount = (int)$params[1];

        if($amount <= 0){
            return "[SimpleEconomy] Amount must be positive.";
        }
        if(strtolower($sender) === strtolower($receiver)){
            return "[SimpleEconomy] You cannot pay yourself.";
        }

        $minTransfer = (int)$this->config->get("min-transfer", 10);
        $maxTransfer = (int)$this->config->get("max-transfer", 10000);
        if($amount < $minTransfer){
            return "[SimpleEconomy] Minimum transfer amount is {$minTransfer} {$this->currency}.";
        }
        if($amount > $maxTransfer){
            return "[SimpleEconomy] Maximum transfer amount is {$maxTransfer} {$this->currency}.";
        }

        $commissionPercent = (float)$this->config->get("transfer-commission", 0);
        $commission = (int) ceil($amount * $commissionPercent / 100);
        $totalCost = $amount + $commission;

        $senderBalance = $this->getBalance($sender);
        if($senderBalance < $totalCost){
            return "[SimpleEconomy] You do not have enough funds. Need: {$totalCost} {$this->currency} (including commission: {$commission} {$this->currency}).";
        }

        $this->reduceBalance($sender, $totalCost);
        $this->addBalance($receiver, $amount);
        $this->addHistory($sender, $receiver, $amount);

        $msg = "[SimpleEconomy] You paid {$amount} {$this->currency} to {$receiver}.";
        if($commission > 0){
            $msg .= " Commission: {$commission} {$this->currency}.";
        }

        $receiverPlayer = $this->api->player->get($receiver);
        if($receiverPlayer instanceof Player){
            $receiverPlayer->sendChat("[SimpleEconomy] You received {$amount} {$this->currency} from {$sender}.");
        }

        return $msg;
    }

    public function balanceTopCommand($cmd, $params, $issuer, $alias){
        $result = $this->db->query("SELECT username, balance FROM balances ORDER BY balance DESC LIMIT 5;");
        if($result === false || $result->numColumns() == 0){
            return "[SimpleEconomy] No players found.";
        }
        $lines = [];
        $rank = 1;
        while($row = $result->fetchArray(SQLITE3_ASSOC)){
            $lines[] = "{$rank}. {$row['username']} - {$row['balance']} {$this->currency}";
            ++$rank;
        }
        if(empty($lines)){
            return "[SimpleEconomy] No players found.";
        }
        return "[SimpleEconomy] Top 5 Richest Players:\n" . implode("\n", $lines);
    }

    private function getHistory($username){
        $username = strtolower($username);
        $stmt = $this->db->prepare("SELECT sender, receiver, amount, time FROM history WHERE sender = :user OR receiver = :user ORDER BY time DESC LIMIT 10;");
        $stmt->bindValue(":user", $username, SQLITE3_TEXT);
        $result = $stmt->execute();
        if($result === false){
            return "[SimpleEconomy] No history found.";
        }
        $lines = [];
        while($row = $result->fetchArray(SQLITE3_ASSOC)){
            $timeStr = date("Y-m-d H:i", $row["time"]);
            $sender = $row["sender"];
            $receiver = $row["receiver"];
            $amount = $row["amount"];
            if($username === $sender){
                $lines[] = "Sent {$amount} {$this->currency} to {$receiver} at {$timeStr}";
            }else{
                $lines[] = "Received {$amount} {$this->currency} from {$sender} at {$timeStr}";
            }
        }
        if(empty($lines)){
            return "[SimpleEconomy] No history found for {$username}.";
        }
        return implode("\n", $lines);
    }

    private static function getOreName($id){
        $map = [
            14 => "gold_ore",
            15 => "iron_ore",
            16 => "coal_ore",
            21 => "lapis_ore",
            56 => "diamond_ore",
            73 => "redstone_ore",
            74 => "glowing_redstone_ore"
        ];
        return $map[$id] ?? null;
    }

    private static function getPeacefulMobName($id){
        $map = [
            10 => "chicken",
            11 => "cow",
            12 => "pig",
            13 => "sheep"
        ];
        return $map[$id] ?? null;
    }

    private static function getHostileMobName($id){
        $map = [
            32 => "zombie",
            33 => "creeper",
            34 => "skeleton",
            35 => "spider",
            36 => "pigman"
        ];
        return $map[$id] ?? null;
    }

    public function __destruct(){
        if(isset($this->db)){
            $this->db->close();
        }
    }
}
