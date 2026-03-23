<?php

/*
__PocketMine Plugin__
name=AutoMine
description=Plugin for creating auto-reset mines
version=1.1
author=MineDg
class=AutoMine
apiversion=12.1
*/

class AutoMine implements Plugin {
    private $api;
    private $config;
    private $positions;
    private $mines;
    private $path;

    public function __construct(ServerAPI $api, $server = false) {
        $this->api = $api;
        $this->positions = [];
        $this->mines = [];
    }

    public function init() {
        $this->path = $this->api->plugin->configPath($this);
        $this->loadConfig();
        $this->api->console->register("automine", "Auto mine management commands", array($this, "commandHandler"));
        $this->api->console->alias("am", "automine");
        
        $this->api->ban->cmdWhitelist('automine');

        $this->api->addHandler("player.block.break", array($this, "onBlockBreak"));
        
        foreach (array_keys($this->mines) as $mineName) {
            $this->scheduleReset($mineName);
        }
    }

    private function loadConfig() {
        $this->config = new Config($this->path . "config.yml", CONFIG_YAML, [
            "ores" => [
                "coal_ore" => 4,
                "iron_ore" => 2,
                "gold_ore" => 2,
                "diamond_ore" => 1,
                "lapis_ore" => 3,
                "redstone_ore" => 3,
                "cobblestone" => 30,
                "stone" => 50
            ],
            "mines" => []
        ]);
        $this->mines = $this->config->get("mines");
        if (!is_array($this->mines)) {
            $this->mines = [];
        }
    }

    private function saveConfig() {
        $this->config->set("mines", $this->mines);
        $this->config->save();
    }

    public function onBlockBreak($data, $event) {
        $player = $data["player"];
        $target = $data["target"];
        
        foreach ($this->mines as $name => $mine) {
            if ($this->isInRegion($target->x, $target->y, $target->z, $mine, $player->level->getName())) {
                if (!$this->api->ban->isOp($player->username)) {
                    $player->sendChat("[AutoMine] You cannot break blocks in mine '$name'.");
                    return false;
                }
            }
        }
        return true;
    }

    private function isInRegion($x, $y, $z, $mine, $worldName) {
        $bx = (int) floor($x);
        $by = (int) floor($y);
        $bz = (int) floor($z);
        
        return $bx >= $mine['x1'] && $bx <= $mine['x2'] &&
               $by >= $mine['y1'] && $by <= $mine['y2'] &&
               $bz >= $mine['z1'] && $bz <= $mine['z2'] &&
               $mine['world'] === $worldName;
    }

    public function commandHandler($cmd, $params, $issuer, $alias) {
        if (!($issuer instanceof Player)) {
            return "This command can only be used in-game";
        }
        
        if (!$this->api->ban->isOp($issuer->username)) {
            return "You don't have permission to use this command.";
        }

        if (count($params) < 1) {
            return $this->getHelp();
        }

        $subCmd = strtolower(array_shift($params));
        switch ($subCmd) {
            case "pos1":
                return $this->setPos1($issuer);
            case "pos2":
                return $this->setPos2($issuer);
            case "create":
                if (isset($params[0])) {
                    return $this->createMine($issuer, $params[0]);
                }
                return "Usage: /automine create <name>";
            case "remove":
                if (isset($params[0])) {
                    return $this->removeMine($params[0]);
                }
                return "Usage: /automine remove <name>";
            case "restime":
                if (count($params) >= 2) {
                    return $this->setResetTime($params[0], (int) $params[1]);
                }
                return "Usage: /automine restime <name> <time_in_seconds>";
            case "reset":
                if (isset($params[0])) {
                    return $this->resetMine($params[0]);
                }
                return "Usage: /automine reset <name>";
            case "list":
                return $this->listMines();
            case "info":
                if (isset($params[0])) {
                    return $this->mineInfo($params[0]);
                }
                return "Usage: /automine info <name>";
            default:
                return $this->getHelp();
        }
    }
    
    private function getHelp() {
        return "/am pos1 - Set first position\n" .
               "/am pos2 - Set second position\n" .
               "/am create <name> - Create a mine\n" .
               "/am remove <name> - Remove a mine\n" .
               "/am restime <name> <seconds> - Set reset time\n" .
               "/am reset <name> - Manually reset a mine\n" .
               "/am list - List all mines\n" .
               "/am info <name> - Mine information";
    }

    private function setPos1($issuer) {
        $x = (int) floor($issuer->entity->x);
        $y = (int) floor($issuer->entity->y) - 1;
        $z = (int) floor($issuer->entity->z);
        
        $this->positions[$issuer->username]['pos1'] = [
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'world' => $issuer->level->getName()
        ];
        return "Pos1 set at ($x, $y, $z)";
    }

    private function setPos2($issuer) {
        $x = (int) floor($issuer->entity->x);
        $y = (int) floor($issuer->entity->y) - 1;
        $z = (int) floor($issuer->entity->z);
        
        $this->positions[$issuer->username]['pos2'] = [
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'world' => $issuer->level->getName()
        ];
        return "Pos2 set at ($x, $y, $z)";
    }

    private function createMine($issuer, $name) {
        if (!isset($this->positions[$issuer->username]['pos1']) || 
            !isset($this->positions[$issuer->username]['pos2'])) {
            return "Please set pos1 and pos2 first";
        }
        
        if (isset($this->mines[$name])) {
            return "Mine '$name' already exists";
        }
        
        $pos1 = $this->positions[$issuer->username]['pos1'];
        $pos2 = $this->positions[$issuer->username]['pos2'];
        
        if ($pos1['world'] !== $pos2['world']) {
            return "Positions must be in the same world";
        }
        
        $this->mines[$name] = [
            'x1' => min($pos1['x'], $pos2['x']),
            'y1' => min($pos1['y'], $pos2['y']),
            'z1' => min($pos1['z'], $pos2['z']),
            'x2' => max($pos1['x'], $pos2['x']),
            'y2' => max($pos1['y'], $pos2['y']),
            'z2' => max($pos1['z'], $pos2['z']),
            'world' => $pos1['world'],
            'resetTime' => 300,
        ];
        $this->saveConfig();
        $this->fillMine($this->mines[$name]);
        $this->scheduleReset($name);
        
        $volume = ($this->mines[$name]['x2'] - $this->mines[$name]['x1'] + 1) *
                  ($this->mines[$name]['y2'] - $this->mines[$name]['y1'] + 1) *
                  ($this->mines[$name]['z2'] - $this->mines[$name]['z1'] + 1);
        
        return "Mine '$name' created ($volume blocks). Reset time: 300s";
    }

    private function removeMine($name) {
        if (!isset($this->mines[$name])) {
            return "Mine '$name' not found";
        }
        unset($this->mines[$name]);
        $this->saveConfig();
        return "Mine '$name' removed";
    }

    private function setResetTime($name, $time) {
        if (!isset($this->mines[$name])) {
            return "Mine '$name' not found";
        }
        if ($time < 10) {
            return "Reset time must be at least 10 seconds";
        }
        $this->mines[$name]['resetTime'] = $time;
        $this->saveConfig();
        return "Reset time for '$name' set to $time seconds";
    }

    private function resetMine($name) {
        if (!isset($this->mines[$name])) {
            return "Mine '$name' not found";
        }
        $this->fillMine($this->mines[$name]);
        return "Mine '$name' has been reset";
    }
    
    private function listMines() {
        if (empty($this->mines)) {
            return "No mines created.";
        }
        $output = "Mines:\n";
        foreach ($this->mines as $name => $mine) {
            $volume = ($mine['x2'] - $mine['x1'] + 1) *
                      ($mine['y2'] - $mine['y1'] + 1) *
                      ($mine['z2'] - $mine['z1'] + 1);
            $output .= "- $name (World: {$mine['world']}, Volume: $volume, Reset: {$mine['resetTime']}s)\n";
        }
        return $output;
    }
    
    private function mineInfo($name) {
        if (!isset($this->mines[$name])) {
            return "Mine '$name' not found";
        }
        $mine = $this->mines[$name];
        $volume = ($mine['x2'] - $mine['x1'] + 1) *
                  ($mine['y2'] - $mine['y1'] + 1) *
                  ($mine['z2'] - $mine['z1'] + 1);
        
        return "Mine: $name\n" .
               "World: {$mine['world']}\n" .
               "Coordinates: ({$mine['x1']}, {$mine['y1']}, {$mine['z1']}) - ({$mine['x2']}, {$mine['y2']}, {$mine['z2']})\n" .
               "Volume: $volume blocks\n" .
               "Reset time: {$mine['resetTime']}s";
    }

    private function fillMine($mine) {
        $level = $this->api->level->get($mine['world']);
        if (!$level) return;

        $ores = $this->config->get("ores");
        
        $oreIDs = [
            "coal_ore" => 16,
            "iron_ore" => 15,
            "gold_ore" => 14,
            "diamond_ore" => 56,
            "lapis_ore" => 21,
            "redstone_ore" => 73,
            "cobblestone" => 4,
            "stone" => 1
        ];
        
        $totalChance = 0;
        foreach ($ores as $ore => $chance) {
            $totalChance += $chance;
        }
        
        for ($x = $mine['x1']; $x <= $mine['x2']; $x++) {
            for ($y = $mine['y1']; $y <= $mine['y2']; $y++) {
                for ($z = $mine['z1']; $z <= $mine['z2']; $z++) {
                    $blockID = 1;
                    $rand = mt_rand(1, max($totalChance, 100));
                    $cumulative = 0;
                    
                    foreach ($ores as $ore => $chance) {
                        $cumulative += $chance;
                        if ($rand <= $cumulative) {
                            if (isset($oreIDs[$ore])) {
                                $blockID = $oreIDs[$ore];
                            }
                            break;
                        }
                    }
                    
                    $level->setBlockRaw(new Vector3($x, $y, $z), BlockAPI::get($blockID, 0), false);
                }
            }
        }
    }

    private function scheduleReset($name) {
        if (!isset($this->mines[$name])) return;
        $time = $this->mines[$name]['resetTime'];
        $this->api->schedule($time * 20, array($this, "doReset"), $name, false);
    }

    public function doReset($name) {
        if (is_array($name) && isset($name[0])) {
            $name = $name[0];
        }

        if (isset($this->mines[$name])) {
            $this->fillMine($this->mines[$name]);
            $this->api->chat->broadcast("[AutoMine] Mine '$name' has been reset!");
            $this->scheduleReset($name);
        }
    }

    public function __destruct() {
        $this->saveConfig();
    }
}
