<?php
/*
__PocketMine Plugin__
name=NCNPC
description=NostalgiaCore NPC plugin
version=1.0
author=MineDg (Based on a plugin by ArkQuark)
class=NPCMain
apiversion=12.1,12.2
*/

class NPCMain implements Plugin {
    private $npcs, $filePath, $api;
    private $spawnedNPCs = [];
    
    public function __construct(ServerAPI $api, $server = false) {
        $this->api = $api;
    }
    
    public function init() {
        $this->filePath = $this->api->plugin->configPath($this)."NPCs.yml";
        EntityRegistry::registerEntity('NPCEntity');
        
        if(!file_exists($this->filePath)) {
            $this->npcs = [];
            $this->api->plugin->writeYAML($this->filePath, $this->npcs);
        } else {
            $this->npcs = $this->api->plugin->readYAML($this->filePath);
        }
        
        $this->api->addHandler("console.command.save-all", [$this, "save"]);
        $this->api->console->register("npc", "<create|set|spawn|list|remove>", [$this, "handleNPCCommand"]);
        
        $this->api->schedule(40, [$this, "spawnAllNPCs"], []);
    }
    
    public function spawnAllNPCs() {
        console("[NCNPC] Spawning all NPCs...");
        foreach($this->npcs as $name => $data) {
            if(isset($this->spawnedNPCs[$name])) {
            }
            $levelName = $data["level"];
            $level = $this->api->level->get($levelName);
            
            if(!$level) {
                console("[NCNPC] Loading level '{$levelName}' for NPC '$name'...");
                $level = $this->api->level->loadLevel($levelName);
                
                if(!$level) {
                    console("[NCNPC] Could not load level '{$levelName}' for NPC '$name'");
                    continue;
                }
            }
            
            $npcData = $this->npcs[$name];
            $npcData["name"] = $name;
            
            $e = $this->api->entity->add($level, ENTITY_MOB, NPCEntity::TYPE, [
                "x" => $data["x"],
                "y" => $data["y"],
                "z" => $data["z"],
                "command" => $npcData["command"],
                "look" => $npcData["look"],
                "hold" => $npcData["hold"],
                "crouched" => $npcData["crouched"],
                "name" => $name,
                "modifyByNpcCommands" => true
            ]);
            
            if($e) {
                $this->spawnedNPCs[$name] = $e->eid;
                $this->api->entity->spawnToAll($e);
                console("[NCNPC] Spawned NPC '$name' at {$data["x"]}, {$data["y"]}, {$data["z"]} in {$data["level"]}");
            } else {
                console("[NCNPC] Failed to spawn NPC '$name'");
            }
        }
        console("[NCNPC] All NPCs spawned");
    }
    
    public function save() {
        $this->api->plugin->writeYAML($this->filePath, $this->npcs);
    }
    
    public function handleNPCCommand($cmd, $args, $issuer, $alias) {
        if(count($args) < 1) {
            return "Usage: /npc <create|set|spawn|list|remove>";
        }
        
        $subcmd = array_shift($args);
        
        switch($subcmd) {
            case "create":
                return $this->createNPC($args, $issuer);
            case "set":
                return $this->setNPC($args, $issuer);
            case "spawn":
                return $this->spawnNPC($args, $issuer);
            case "list":
                return $this->listNPCs($issuer);
            case "remove":
                return $this->removeNPC($args, $issuer);
            default:
                return "Unknown subcommand. Usage: /npc <create|set|spawn|list|remove>";
        }
    }
    
    public function createNPC($args, $issuer) {
        if(!$issuer instanceof Player) {
            return "Please use this command in-game!";
        }
        
        if(count($args) < 1) {
            return "Usage: /npc create <name>";
        }
        
        $name = $args[0];
        
        if(isset($this->npcs[$name])) {
            return "NPC with name '$name' already exists!";
        }
        
        $this->npcs[$name] = [
            "x" => $issuer->entity->x,
            "y" => $issuer->entity->y,
            "z" => $issuer->entity->z,
            "level" => $issuer->entity->level->getName(),
            "command" => "",
            "look" => false,
            "hold" => 0,
            "crouched" => false
        ];
        
        $this->save();
        return "NPC '$name' created! Use /npc spawn $name to spawn it.";
    }
    
    public function setNPC($args, $issuer) {
        if(count($args) < 2) {
            return "Usage: /npc set <name> <look|hold|command|crouched> [value]";
        }
        
        $name = $args[0];
        $setting = $args[1];
        
        if(!isset($this->npcs[$name])) {
            return "NPC with name '$name' doesn't exist!";
        }
        
        switch($setting) {
            case "look":
                if(!isset($args[2]) || !in_array($args[2], ["true", "false"])) {
                    return "Usage: /npc set $name look <true|false>";
                }
                $this->npcs[$name]["look"] = ($args[2] === "true");
                $this->save();
                return "NPC '$name' look setting set to " . $args[2];
                
            case "hold":
                if(count($args) < 3) {
                    return "Usage: /npc set $name hold <id>";
                }
                $id = intval($args[2]);
                $this->npcs[$name]["hold"] = $id;
                $this->save();
                return "NPC '$name' hold item set to $id";
                
            case "command":
                if(count($args) < 3) {
                    return "Usage: /npc set $name command <command>";
                }
                $command = implode(" ", array_slice($args, 2));
                $this->npcs[$name]["command"] = $command;
                $this->save();
                return "NPC '$name' command set to: $command";
                
            case "crouched":
                if(!isset($args[2]) || !in_array($args[2], ["true", "false"])) {
                    return "Usage: /npc set $name crouched <true|false>";
                }
                $this->npcs[$name]["crouched"] = ($args[2] === "true");
                $this->save();
                return "NPC '$name' crouched setting set to " . $args[2];
                
            default:
                return "Unknown setting. Available settings: look, hold, command, crouched";
        }
    }
    
    public function spawnNPC($args, $issuer) {
        if(!$issuer instanceof Player) {
            return "Please use this command in-game!";
        }
        
        if(count($args) < 1) {
            return "Usage: /npc spawn <name>";
        }
        
        $name = $args[0];
        
        if(!isset($this->npcs[$name])) {
            return "NPC with name '$name' doesn't exist!";
        }
        
        if(isset($this->spawnedNPCs[$name])) {
            return "NPC '$name' is already spawned!";
        }
        
        $npcData = $this->npcs[$name];
        $npcData["name"] = $name;
        
        $e = $this->api->entity->add($issuer->level, ENTITY_MOB, NPCEntity::TYPE, [
            "x" => $issuer->entity->x,
            "y" => $issuer->entity->y,
            "z" => $issuer->entity->z,
            "command" => $npcData["command"],
            "look" => $npcData["look"],
            "hold" => $npcData["hold"],
            "crouched" => $npcData["crouched"],
            "name" => $name,
            "modifyByNpcCommands" => true
        ]);
        $this->spawnedNPCs[$name] = $e->eid;
        $this->api->entity->spawnToAll($e);
        $this->npcs[$name]["x"] = $issuer->entity->x;
        $this->npcs[$name]["y"] = $issuer->entity->y;
        $this->npcs[$name]["z"] = $issuer->entity->z;
        $this->npcs[$name]["level"] = $issuer->entity->level->getName();
        $this->save();
        return "NPC '$name' spawned!";
    }
    
    public function listNPCs($issuer) {
        if(count($this->npcs) === 0) {
            return "No NPCs created yet.";
        }
        $output = "NPCs list:\n";
        foreach($this->npcs as $name => $data) {
            $status = isset($this->spawnedNPCs[$name]) ? "Spawned" : "Not spawned";
            $output .= "- $name ($status)\n";
        }
        return $output;
    }
    
    public function removeNPC($args, $issuer) {
        if(count($args) < 1) {
            return "Usage: /npc remove <name>";
        }
        $name = $args[0];
        if(!isset($this->npcs[$name])) {
            return "NPC with name '$name' doesn't exist!";
        }
        if(isset($this->spawnedNPCs[$name])) {
            $eid = $this->spawnedNPCs[$name];
            $entity = $this->api->entity->get($eid);
            if($entity) {
                $entity->close();
            }
            unset($this->spawnedNPCs[$name]);
        }
        
        unset($this->npcs[$name]);
        $this->save();
        return "NPC '$name' removed!";
    }
    
    public function __destruct() {
        $this->save();
    }
}

class NPCEntity extends Zombie {
    const TYPE = -9999;
    
    function __construct(Level $level, $eid, $class, $type = 0, $data = array()) {
        $weirdcode1 = self::$despawnMobs;
        self::$despawnMobs = false;
        parent::__construct($level, $eid, $class, MOB_ZOMBIE, $data);
        self::$despawnMobs = $weirdcode1;
        $this->ai = new \EntityAI($this);
        $this->setName($data["name"] ?? "NPC");
        $this->yaw = $this->pitch = 0;
        $this->crouched = $data["crouched"] ?? false;
        $this->data = $data;
    }
    
    public function update($now) {
        if($this->data["look"] ?? false) {
            $this->server->api->schedule(10, [$this, "looking"], []);
        }
        parent::update($now);
    }
    
    public function looking() {
        if($this->data["look"] ?? false) {
            $this->ai->mobController->lookOn($this->findTarget($this, 10));
        }
    }
    
    protected function findTarget($e, $r = 5) {
        $svd = null;
        $svdDist = -1;
        foreach($e->server->api->entity->getRadius($e, $r, ENTITY_PLAYER) as $p) {
            if($svdDist === -1) {
                $svdDist = Utils::manh_distance($e, $p);
                $svd = $p;
                continue;
            }
            if($svd != null && $svdDist === 0) {
                $svd = $p;
            }
            
            if(($cd = Utils::manh_distance($e, $p)) < $svdDist) {
                $svdDist = $cd;
                $svd = $p;
            }
        }
        return $svd;
    }
    
    public function updateBurning() {}
    
    public function harm($dmg, $cause = "generic", $force = false) {
        if(is_numeric($cause)) {
            $e = $this->server->api->entity->get($cause);
            if($e->isPlayer() && !empty($this->data["command"])) {
                $this->server->api->console->run($this->data["command"], $e->player);
            }
        }
        return false;
    }
    
    public function getMetadata() {
        $d = parent::getMetadata();
        return $d;
    }
    
    public function spawn($player) {
        if(!($player instanceof Player)) {
            $player = $this->server->api->player->get($player);
        }
        if($player->eid === $this->eid or $this->closed !== false or ($player->level !== $this->level and $this->class !== ENTITY_PLAYER)) {
            return false;
        }
        
        $pk = new AddEntityPacket();
        $pk->eid = $this->eid;
        $pk->type = $this->type;
        $pk->x = $this->x;
        $pk->y = $this->y;
        $pk->z = $this->z;
        $pk->did = 0;
        $player->dataPacket($pk);
        
        $pk = new AddPlayerPacket();
        $pk->clientID = 0;
        $pk->username = $this->getName();
        $pk->eid = $this->eid;
        $pk->x = $this->x;
        $pk->y = $this->y;
        $pk->z = $this->z;
        $pk->yaw = $this->yaw;
        $pk->pitch = $this->pitch;
        $pk->itemID = $this->data["hold"] ?? 0;
        $pk->itemAuxValue = 0;
        $pk->metadata = $this->getMetadata();
        $pk->crouched = $this->crouched;
        $player->dataPacket($pk);
    }
}