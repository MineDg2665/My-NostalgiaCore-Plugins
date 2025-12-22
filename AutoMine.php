<?php

/*
__PocketMine Plugin__
name=AutoMine
description=Plugin for creating auto-reset mines
version=1.0
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
        $this->api->ban->cmdWhitelist('am');

        $this->api->addHandler("player.join", array($this, "onPlayerJoin"));
        $this->api->addHandler("player.quit", array($this, "onPlayerQuit"));
        $this->api->addHandler("player.block.break", array($this, "onBlockBreak"));
        
        foreach(array_keys($this->mines) as $mineName){
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
    }

    private function saveConfig() {
        $this->config->set("mines", $this->mines);
        $this->config->save();
    }

    public function onPlayerJoin($data) {
        $player = $data->username;
        if (!isset($this->positions[$player])) {
            $this->positions[$player] = ['pos1' => null, 'pos2' => null];
        }
    }

    public function onPlayerQuit($data) {
        $player = $data->username;
        unset($this->positions[$player]);
    }

    public function onBlockBreak($data, $event) {
        $player = $data["player"];
        $target = $data["target"];
        foreach ($this->mines as $name => $mine) {
            if ($this->isInRegion($target->x, $target->y, $target->z, $mine)) {
                if (!$this->api->ban->isOp($player->username)) {
                    return false;
                }
            }
        }
    }

    private function isInRegion($x, $y, $z, $mine) {
        return $x >= $mine['x1'] && $x <= $mine['x2'] &&
               $y >= $mine['y1'] && $y <= $mine['y2'] &&
               $z >= $mine['z1'] && $z <= $mine['z2'] &&
               $mine['world'] === $this->api->level->getDefault()->getName();
    }

    public function commandHandler($cmd, $params, $issuer, $alias) {
        if (!($issuer instanceof Player)) {
            return "This command can only be used in-game";
        }

        if (count($params) < 1) {
            return "Usage: /automine <pos1|pos2|create|remove|restime|reset>";
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
                    $name = $params[0];
                    $time = (int) $params[1];
                    return $this->setResetTime($name, $time);
                }
                return "Usage: /automine restime <name> <time>";
            case "reset":
                if (isset($params[0])) {
                    return $this->resetMine($params[0]);
                }
                return "Usage: /automine reset <name>";
            default:
                return "Usage: /automine <pos1|pos2|create|remove|restime|reset>";
        }
    }

    private function setPos1($issuer) {
        $this->positions[$issuer->username]['pos1'] = [
            'x' => floor($issuer->entity->x),
            'y' => floor($issuer->entity->y),
            'z' => floor($issuer->entity->z),
            'world' => $issuer->level->getName()
        ];
        return "Pos1 set at ({$this->positions[$issuer->username]['pos1']['x']}, {$this->positions[$issuer->username]['pos1']['y']}, {$this->positions[$issuer->username]['pos1']['z']})";
    }

    private function setPos2($issuer) {
        $this->positions[$issuer->username]['pos2'] = [
            'x' => floor($issuer->entity->x),
            'y' => floor($issuer->entity->y),
            'z' => floor($issuer->entity->z),
            'world' => $issuer->level->getName()
        ];
        return "Pos2 set at ({$this->positions[$issuer->username]['pos2']['x']}, {$this->positions[$issuer->username]['pos2']['y']}, {$this->positions[$issuer->username]['pos2']['z']})";
    }

    private function createMine($issuer, $name) {
        if (!isset($this->positions[$issuer->username]['pos1']) || !isset($this->positions[$issuer->username]['pos2'])) {
            return "Please set pos1 and pos2 first";
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
        $this->scheduleReset($name);
        return "Mine '$name' created";
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
        $this->mines[$name]['resetTime'] = $time;
        $this->saveConfig();
        return "Reset time for '$name' set to $time seconds";
    }

    private function resetMine($name) {
        if (!isset($this->mines[$name])) {
            return "Mine '$name' not found";
        }
        $mine = $this->mines[$name];
        $this->fillMine($mine);
        return "Mine '$name' reset manually";
    }

    private function fillMine($mine) {
        $level = $this->api->level->get($mine['world']);
        if(!$level) return; 

        $ores = $this->config->get("ores");
        for ($x = $mine['x1']; $x <= $mine['x2']; $x++) {
            for ($y = $mine['y1']; $y <= $mine['y2']; $y++) {
                for ($z = $mine['z1']; $z <= $mine['z2']; $z++) {
                    $blockID = 1;
                    $rand = rand(1, 100);
                    $cumulative = 0;
                    foreach ($ores as $ore => $chance) {
                        $cumulative += $chance;
                        if ($rand <= $cumulative) {
                            $constName = strtoupper($ore);
                            if(defined($constName)){
                                $blockID = constant($constName);
                            } else {
                                $blockID = 1; 
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
        if(!isset($this->mines[$name])) return;
        $time = $this->mines[$name]['resetTime'];
        $this->api->schedule($time * 20, array($this, "doReset"), array($name), false, $name . "_reset");
    }

    public function doReset($arg) {
        $name = $arg;
        if(is_array($arg) && isset($arg[0])){
            $name = $arg[0];
        }

        if (isset($this->mines[$name])) {
            $this->fillMine($this->mines[$name]);
            $this->scheduleReset($name);
        }
    }

    public function __destruct() {
        $this->saveConfig();
    }
}