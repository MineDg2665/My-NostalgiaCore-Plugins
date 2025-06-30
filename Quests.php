<?php

/*
__PocketMine Plugin__
name=Quests
version=1.4
description=Plugin for Quests!!!
author=MineDg
class=QuestPlugin
apiversion=12.1
*/
//1.4 Added per-player quest tracking

//1.3 Fixed player.move

//1.2 Added quest type for walking to a point

//1.1 Removed completed and in progress + remove items from inventory, not from hand

//1.0 Plugin created!

class QuestPlugin implements Plugin {
    private $api;
    private $quests;
    private $playerProgress;
    private $currentQuest;

    public function __construct(ServerAPI $api, $server = false) {
        $this->api = $api;
        $this->quests = [];
        $this->playerProgress = [];
        $this->currentQuest = null; 
    }

    public function init() {
        $this->loadQuests();
        $this->loadPlayerProgress();
        $this->api->console->register("quest", "Quest management commands", array($this, "commandHandler"));
        
        $this->api->addHandler("player.quit", function($data, $event) {
            $this->saveQuests();
            $this->savePlayerProgress();
        }, 15);

        $this->api->addHandler("player.move", array($this, "onPlayerMove"));
        $this->api->ban->cmdWhitelist('quest');
    }

    private function loadQuests() {
        $path = $this->api->plugin->configPath($this) . "quests.yml";
        if (file_exists($path)) {
            $this->quests = yaml_parse_file($path);
        } else {
            $this->quests = [];
        }
    }

    private function saveQuests() {
        $path = $this->api->plugin->configPath($this) . "quests.yml";
        yaml_emit_file($path, $this->quests);
    }
    
    private function loadPlayerProgress() {
        $path = $this->api->plugin->configPath($this) . "player_progress.yml";
        if (file_exists($path)) {
            $this->playerProgress = yaml_parse_file($path);
        } else {
            $this->playerProgress = [];
        }
    }

    private function savePlayerProgress() {
        $path = $this->api->plugin->configPath($this) . "player_progress.yml";
        yaml_emit_file($path, $this->playerProgress);
    }

    private function getPlayerCompletedQuests($playerName) {
        $playerName = strtolower($playerName);
        if (!isset($this->playerProgress[$playerName])) {
            $this->playerProgress[$playerName] = [];
        }
        return $this->playerProgress[$playerName];
    }

    private function markQuestCompleted($playerName, $questName) {
        $playerName = strtolower($playerName);
        if (!isset($this->playerProgress[$playerName])) {
            $this->playerProgress[$playerName] = [];
        }
        if (!in_array($questName, $this->playerProgress[$playerName])) {
            $this->playerProgress[$playerName][] = $questName;
            $this->savePlayerProgress();
        }
    }

    private function hasCompletedQuest($playerName, $questName) {
        $playerName = strtolower($playerName);
        if (!isset($this->playerProgress[$playerName])) {
            return false;
        }
        return in_array($questName, $this->playerProgress[$playerName]);
    }

    public function commandHandler($cmd, $params, $issuer, $alias) {
        $output = "";
        if (count($params) < 1) {
            return "Usage: /quest <add|remove|list|complete|info>";
        }

        if (!($issuer instanceof Player)) {
            return "This command can only be used in-game";
        }

        $isOp = $this->api->ban->isOp($issuer->username);

        switch (strtolower($params[0])) {
            case "add":
                if (!$isOp) {
                    return "You do not have permission to use this command";
                }
                if (count($params) < 2) {
                    return "Usage: /quest add <type> <params>";
                }
                switch (strtolower($params[1])) {
                    case "items":
                        return $this->addItemsQuest($params, $issuer);
                    case "go":
                        return $this->addGoQuest($params, $issuer);
                    default:
                        return "Unknown quest type";
                }
            case "remove":
                if (!$isOp) {
                    return "You do not have permission to use this command";
                }
                return $this->removeQuest($params, $issuer);
            case "list":
                return $this->listQuests($issuer);
            case "complete":
                return $this->completeQuest($params, $issuer);
            case "info":
                return $this->questInfo($issuer);
            case "reset":
                if (!$isOp) {
                    return "You do not have permission to use this command";
                }
                if (count($params) < 2) {
                    return "Usage: /quest reset <player>";
                }
                return $this->resetPlayerProgress($params[1]);
            default:
                return "Unknown command. Use /quest <add|remove|list|complete|info>";
        }
    }

    private function addItemsQuest($params, Player $issuer) {
        if (count($params) < 7) {
            return "Usage: /quest add items <name> <item_id> <meta> <amount> <reward_id> <reward_meta> <reward_count>";
        }
        $name = $params[2];
        $item_id = (int)$params[3];
        $meta = (int)$params[4];
        $amount = (int)$params[5];
        $reward_id = (int)$params[6];
        $reward_meta = (int)$params[7];
        $reward_count = (int)$params[8];

        $this->quests[$name] = [
            "type" => "items",
            "item_id" => $item_id,
            "meta" => $meta,
            "amount" => $amount,
            "reward" => [
                "id" => $reward_id,
                "meta" => $reward_meta,
                "count" => $reward_count
            ]
        ];
        $this->saveQuests();

        return "Quest '$name' added! Collect $amount of item ID $item_id with metadata $meta. Reward: $reward_count of item ID $reward_id with metadata $reward_meta";
    }

    private function addGoQuest($params, Player $issuer) {
        if (count($params) < 10) {
            return "Usage: /quest add go <name> <x> <y> <z> <world> <reward_id> <reward_meta> <reward_count>";
        }

        $name = $params[2];
        $x = (int)$params[3];
        $y = (int)$params[4];
        $z = (int)$params[5];
        $world = $params[6];
        $reward_id = (int)$params[7];
        $reward_meta = (int)$params[8];
        $reward_count = isset($params[9]) ? (int)$params[9] : 1;

        $this->quests[$name] = [
            "type" => "go",
            "location" => [
                "x" => $x,
                "y" => $y,
                "z" => $z,
                "world" => $world
            ],
            "reward" => [
                "id" => $reward_id,
                "meta" => $reward_meta,
                "count" => $reward_count
            ]
        ];
        $this->saveQuests();

        return "Quest '$name' added! Go to coordinates ($x, $y, $z) in world '$world'. Reward: $reward_count of item ID $reward_id with metadata $reward_meta";
    }

    private function resetPlayerProgress($playerName) {
        $playerName = strtolower($playerName);
        if (isset($this->playerProgress[$playerName])) {
            unset($this->playerProgress[$playerName]);
            $this->savePlayerProgress();
            return "Progress reset for player $playerName";
        }
        return "No progress found for player $playerName";
    }

    private function completeQuest($params, Player $issuer) {
         if (count($params) < 2) {
            return "Usage: /quest complete <quest_name>";
        }
        $quest_name = $params[1];

        if (!isset($this->quests[$quest_name])) {
            return "Quest '$quest_name' not found!";
        }
        
        if ($this->hasCompletedQuest($issuer->username, $quest_name)) {
            return "You have already completed this quest!";
        }

        $quest = $this->quests[$quest_name];
        
        if ($quest["type"] === "items") {
            $hasItem = false;
            foreach ($issuer->inventory as $slot => $item) {
                if ($item->getID() === $quest["item_id"] && $item->getMetadata() === $quest["meta"] && $item->count >= $quest["amount"]) {
                    $hasItem = true;
                    $issuer->removeItem($quest["item_id"], $quest["meta"], $quest["amount"]);
                    break;
                }
            }

            if (!$hasItem) {
                return "You do not have enough items to complete this quest!";
            }

            $rewardItem = BlockAPI::getItem($quest["reward"]["id"], $quest["reward"]["meta"], $quest["reward"]["count"]);
            $this->api->entity->drop(new Position($issuer->entity->x - 0.5, $issuer->entity->y, $issuer->entity->z - 0.5, $issuer->entity->level), $rewardItem);
            
            $this->markQuestCompleted($issuer->username, $quest_name);
            
            return "Quest '$quest_name' completed! You have received {$quest["reward"]["count"]} of item ID {$quest["reward"]["id"]}";
        } else {
            return "This quest type cannot be completed manually.";
        }
	} 
    
	private function completeGoQuest($questName, Player $player) {
        if (!isset($this->quests[$questName])) {
            return false;
        }
        
        if ($this->hasCompletedQuest($player->username, $questName)) {
            return false;
        }

        $quest = $this->quests[$questName];
        $rewardItem = BlockAPI::getItem($quest["reward"]["id"], $quest["reward"]["meta"], $quest["reward"]["count"]);
        $this->api->entity->drop(new Position($player->entity->x - 0.5, $player->entity->y, $player->entity->z - 0.5, $player->entity->level), $rewardItem);

        $player->sendChat("Quest '$questName' completed! You have received {$quest["reward"]["count"]} of item ID {$quest["reward"]["id"]}");

        $this->markQuestCompleted($player->username, $questName);

        return true;
    }

    
    private function listQuests(Player $issuer) {
        $quests = $this->getAvailableQuestsForPlayer($issuer->username);
        
        if (empty($quests)) {
            return "No quests available for you";
        }

        $output = "Available quests:\n";
        foreach ($quests as $name => $quest) {
            if ($quest['type'] === "items") {
                $output .= "$name: Collect {$quest['amount']} of item ID {$quest['item_id']} (meta: {$quest['meta']})\n";
            } elseif ($quest['type'] === "go") {
                $output .= "$name: Go to coordinates ({$quest['location']['x']}, {$quest['location']['y']}, {$quest['location']['z']}) in world '{$quest['location']['world']}'\n";
            }
        }

        if ($this->api->ban->isOp($issuer->username)) {
            $completedQuests = $this->getPlayerCompletedQuests($issuer->username);
            if (!empty($completedQuests)) {
                $output .= "\nCompleted quests:\n";
                foreach ($completedQuests as $name) {
                    if (isset($this->quests[$name])) {
                        $output .= "$name\n";
                    }
                }
            }
        }

        return $output;
    }
    
    private function getAvailableQuestsForPlayer($playerName) {
        $completedQuests = $this->getPlayerCompletedQuests($playerName);
        $availableQuests = [];
        
        foreach ($this->quests as $name => $quest) {
            if (!in_array($name, $completedQuests)) {
                $availableQuests[$name] = $quest;
            }
        }
        
        return $availableQuests;
    }

    private function questInfo(Player $issuer) {
        $quests = $this->getAvailableQuestsForPlayer($issuer->username);
        
        if (empty($quests)) {
            return "No quests available for you";
        }

        $questNames = array_keys($quests);
        return "Your next quest: " . $questNames[0] . " - " . $this->getQuestDescription($questNames[0]);
    }

    private function getQuestDescription($questName) {
        $quest = $this->quests[$questName];
        if ($quest['type'] === "items") {
            return "Collect {$quest['amount']} of item ID {$quest['item_id']} (meta: {$quest['meta']})";
        } elseif ($quest['type'] === "go") {
            return "Go to coordinates ({$quest['location']['x']}, {$quest['location']['y']}, {$quest['location']['z']}) in world '{$quest['location']['world']}'";
        }
        return "Unknown quest type.";
    }

    private function removeQuest($params, Player $issuer) {
        if (count($params) < 2) {
            return "Usage: /quest remove <quest_name>";
        }
        $quest_name = $params[1];

        if (!isset($this->quests[$quest_name])) {
            return "Quest '$quest_name' not found!";
        }

        unset($this->quests[$quest_name]);
        $this->saveQuests();
        
        foreach ($this->playerProgress as $playerName => $completedQuests) {
            if (($key = array_search($quest_name, $completedQuests)) !== false) {
                unset($this->playerProgress[$playerName][$key]);
                $this->playerProgress[$playerName] = array_values($this->playerProgress[$playerName]);
            }
        }
        $this->savePlayerProgress();

        return "Quest '$quest_name' has been removed";
    }

    public function onPlayerMove($data) {
        $player = $data->player;
        if(!($player instanceof Player)) return;

        $x = floor($data->x);
        $y = floor($data->y);
        $z = floor($data->z);
        $level = $player->level->getName();

        foreach ($this->quests as $name => $quest) {
            if ($quest['type'] === "go") {
                if (!$this->hasCompletedQuest($player->username, $name) &&
                    $level === $quest["location"]["world"] &&
                    abs($x - $quest["location"]["x"]) <= 1 &&
                    abs($y - $quest["location"]["y"]) <= 1 &&
                    abs($z - $quest["location"]["z"]) <= 1) {

                    $this->completeGoQuest($name, $player);
                    break;
                }
            }
        }
    }

    public function __destruct() {
        $this->saveQuests();
        $this->savePlayerProgress();
    }
}