<?php
/*
__PocketMine Plugin__
name=NPC
description=A plugin that adds custom NPCs
version=1.2
author=MineDg
class=NPCMain
apiversion=12.1
*/

class NPCMain implements Plugin {
    private $npcs;
    private $filePath;
    private $api;
    private $spawnedNPCs = [];
    private $npcEntityData = [];

    public function __construct(ServerAPI $api, $server = false) {
        $this->api = $api;
    }

    public function init() {
        $this->filePath = $this->api->plugin->configPath($this) . "NPCs.yml";

        if (!file_exists($this->filePath)) {
            $this->npcs = [];
            $this->api->plugin->writeYAML($this->filePath, $this->npcs);
        } else {
            $this->npcs = $this->api->plugin->readYAML($this->filePath);
            if (!is_array($this->npcs)) {
                $this->npcs = [];
            }
        }

        $this->api->addHandler("console.command.save-all", [$this, "save"]);
        $this->api->addHandler("entity.health.change", [$this, "onEntityHealthChange"], 1);
        $this->api->addHandler("player.spawn", [$this, "onPlayerSpawn"], 15);
        $this->api->console->register("npc", "<create|set|spawn|list|remove|respawn>", [$this, "handleNPCCommand"]);

        $this->api->schedule(60, [$this, "spawnAllNPCs"], []);
        $this->api->schedule(3, [$this, "lookTick"], [], true);
        $this->api->schedule(20 * 60, [$this, "recheckNPCs"], [], true);
    }

    public function onPlayerSpawn($data) {
        $player = $data;
        if (!($player instanceof Player)) {
            return;
        }

        $this->api->schedule(20, [$this, "sendNPCsToPlayer"], $player);
    }

    public function sendNPCsToPlayer($player) {
        if (is_array($player) && isset($player[0])) {
            $player = $player[0];
        }

        if (!($player instanceof Player) || $player->connected !== true || $player->spawned !== true) {
            return;
        }

        foreach ($this->spawnedNPCs as $name => $eid) {
            $entity = $this->api->entity->get($eid);
            if (!$entity || $entity->closed === true) {
                continue;
            }
            if ($player->level !== $entity->level) {
                continue;
            }
            $this->sendNPCPacket($entity, $player, $name);
        }
    }

    public function onEntityHealthChange($data) {
        $entity = $data["entity"];
        $eid = $entity->eid;
        $cause = $data["cause"];

        if (!isset($this->npcEntityData[$eid])) {
            return;
        }

        $npcData = $this->npcEntityData[$eid];

        if (is_numeric($cause)) {
            $attacker = $this->api->entity->get($cause);
            if ($attacker !== false && $attacker->isPlayer() && !empty($npcData["command"])) {
                $cmd = str_replace("{player}", $attacker->player->username, $npcData["command"]);
                $this->api->console->run($cmd, $attacker->player);
            }
        }

        return false;
    }

    public function recheckNPCs() {
        foreach ($this->spawnedNPCs as $name => $eid) {
            $entity = $this->api->entity->get($eid);
            if (!$entity || $entity->closed === true || $entity->dead === true) {
                console("[NPC] NPC '$name' disappeared, respawning...");
                unset($this->spawnedNPCs[$name]);
                unset($this->npcEntityData[$eid]);
                $this->spawnSingleNPC($name);
            }
        }
    }

    public function spawnAllNPCs() {
        console("[NPC] Spawning all NPCs...");
        foreach ($this->npcs as $name => $data) {
            if (isset($this->spawnedNPCs[$name])) {
                $entity = $this->api->entity->get($this->spawnedNPCs[$name]);
                if ($entity && $entity->closed !== true && $entity->dead !== true) {
                    continue;
                }
                $oldEid = $this->spawnedNPCs[$name];
                unset($this->spawnedNPCs[$name]);
                unset($this->npcEntityData[$oldEid]);
            }
            $this->spawnSingleNPC($name);
        }
        console("[NPC] Done spawning NPCs");
    }

    private function spawnSingleNPC($name) {
        if (!isset($this->npcs[$name])) {
            return false;
        }

        $data = $this->npcs[$name];
        $levelName = $data["level"];
        $level = $this->api->level->get($levelName);

        if ($level === false || $level === null) {
            $this->api->level->loadLevel($levelName);
            $level = $this->api->level->get($levelName);
            if ($level === false || $level === null) {
                console("[NPC] Could not load level '$levelName' for NPC '$name'");
                return false;
            }
        }

        $e = $this->api->entity->add($level, ENTITY_MOB, MOB_ZOMBIE, [
            "x" => (float) $data["x"],
            "y" => (float) $data["y"],
            "z" => (float) $data["z"],
            "Health" => 20,
        ]);

        if (!$e) {
            console("[NPC] Failed to create entity for NPC '$name'");
            return false;
        }

        $e->setName($name);
        $e->x = (float) $data["x"];
        $e->y = (float) $data["y"];
        $e->z = (float) $data["z"];
        $e->setHealth(20, "generic", true, false);
        $e->canBeAttacked = true;
        $e->dead = false;
        $e->speedX = 0;
        $e->speedY = 0;
        $e->speedZ = 0;
        $e->speed = 0;
        $e->crouched = $data["crouched"] ?? false;

        if (isset($e->ai) && $e->ai !== null) {
            $e->ai->clearTasks();
        }

        $e->check = false;

        $this->spawnedNPCs[$name] = $e->eid;
        $this->npcEntityData[$e->eid] = [
            "command" => $data["command"] ?? "",
            "look" => $data["look"] ?? false,
            "hold" => $data["hold"] ?? 0,
            "crouched" => $data["crouched"] ?? false,
            "name" => $name,
        ];

        $e->updateAABB();

        $players = $this->api->player->getAll($level);
        foreach ($players as $player) {
            if ($player->spawned === true && $player->connected === true) {
                $this->sendNPCPacket($e, $player, $name);
            }
        }

        console("[NPC] Spawned NPC '$name' at {$data["x"]}, {$data["y"]}, {$data["z"]} in $levelName");
        return true;
    }

    private function sendNPCPacket($entity, Player $player, $name) {
        if ($player->eid === $entity->eid) {
            return;
        }
        if ($player->level !== $entity->level) {
            return;
        }

        $eid = $entity->eid;
        $npcData = $this->npcEntityData[$eid] ?? [];

        $pk = new RemoveEntityPacket();
        $pk->eid = $eid;
        $player->dataPacket($pk);

        $pk = new RemovePlayerPacket();
        $pk->eid = $eid;
        $pk->clientID = 0;
        $player->dataPacket($pk);

        $pk = new AddPlayerPacket();
        $pk->clientID = 0;
        $pk->username = $npcData["name"] ?? $name;
        $pk->eid = $eid;
        $pk->x = $entity->x;
        $pk->y = $entity->y;
        $pk->z = $entity->z;
        $pk->yaw = $entity->yaw;
        $pk->pitch = $entity->pitch;
        $pk->itemID = $npcData["hold"] ?? 0;
        $pk->itemAuxValue = 0;
        $pk->metadata = $entity->getMetadata();
        $player->dataPacket($pk);
    }

    public function lookTick() {
        foreach ($this->spawnedNPCs as $name => $eid) {
            if (!isset($this->npcEntityData[$eid])) {
                continue;
            }

            $npcData = $this->npcEntityData[$eid];
            if (!($npcData["look"] ?? false)) {
                continue;
            }

            $entity = $this->api->entity->get($eid);
            if (!$entity || $entity->closed === true) {
                continue;
            }

            $nearest = $this->findNearestPlayer($entity, 10);
            if ($nearest === null) {
                continue;
            }

            $dx = $nearest->x - $entity->x;
            $dy = ($nearest->y + $nearest->getEyeHeight()) - ($entity->y + $entity->getEyeHeight());
            $dz = $nearest->z - $entity->z;
            $dist = sqrt($dx * $dx + $dz * $dz);

            $yaw = (-atan2($dx, $dz) * 180 / M_PI);
            $pitch = (-atan2($dy, $dist) * 180 / M_PI);

            $entity->yaw = $yaw;
            $entity->pitch = $pitch;
            $entity->headYaw = $yaw;

            $players = $this->api->player->getAll($entity->level);
            foreach ($players as $player) {
                if ($player->eid === $eid) {
                    continue;
                }

                $pk = new MoveEntityPacket_PosRot();
                $pk->eid = $eid;
                $pk->x = $entity->x;
                $pk->y = $entity->y;
                $pk->z = $entity->z;
                $pk->yaw = $yaw;
                $pk->pitch = $pitch;
                $player->dataPacket($pk);

                $pk = new RotateHeadPacket();
                $pk->eid = $eid;
                $pk->yaw = $yaw;
                $player->dataPacket($pk);
            }
        }
    }

    private function findNearestPlayer($entity, $radius) {
        $nearest = null;
        $nearestDistSq = $radius * $radius;

        $players = $this->api->player->getAll($entity->level);
        foreach ($players as $player) {
            if (!isset($player->entity) || $player->entity === false) {
                continue;
            }
            if ($player->spawned !== true) {
                continue;
            }
            $dx = $player->entity->x - $entity->x;
            $dy = $player->entity->y - $entity->y;
            $dz = $player->entity->z - $entity->z;
            $distSq = $dx * $dx + $dy * $dy + $dz * $dz;

            if ($distSq < $nearestDistSq) {
                $nearestDistSq = $distSq;
                $nearest = $player->entity;
            }
        }

        return $nearest;
    }

    public function save() {
        $this->api->plugin->writeYAML($this->filePath, $this->npcs);
    }

    public function handleNPCCommand($cmd, $args, $issuer, $alias) {
        if (count($args) < 1) {
            return "Usage: /npc <create|set|spawn|list|remove|respawn>";
        }

        $subcmd = strtolower(array_shift($args));

        switch ($subcmd) {
            case "create":
                return $this->createNPC($args, $issuer);
            case "set":
                return $this->setNPC($args, $issuer);
            case "spawn":
                return $this->cmdSpawnNPC($args, $issuer);
            case "list":
                return $this->listNPCs();
            case "remove":
                return $this->removeNPC($args);
            case "respawn":
                return $this->respawnNPC($args);
            default:
                return "Unknown subcommand. Usage: /npc <create|set|spawn|list|remove|respawn>";
        }
    }

    public function createNPC($args, $issuer) {
        if (!($issuer instanceof Player)) {
            return "Please use this command in-game!";
        }

        if (count($args) < 1) {
            return "Usage: /npc create <name>";
        }

        $name = $args[0];

        if (isset($this->npcs[$name])) {
            return "NPC '$name' already exists!";
        }

        $this->npcs[$name] = [
            "x" => round($issuer->entity->x, 2),
            "y" => round($issuer->entity->y, 2),
            "z" => round($issuer->entity->z, 2),
            "level" => $issuer->entity->level->getName(),
            "command" => "",
            "look" => false,
            "hold" => 0,
            "crouched" => false,
        ];

        $this->save();
        return "NPC '$name' created! Use /npc spawn $name to spawn it.";
    }

    public function setNPC($args, $issuer) {
        if (count($args) < 2) {
            return "Usage: /npc set <name> <look|hold|command|crouched|pos> [value]";
        }

        $name = $args[0];
        $setting = strtolower($args[1]);

        if (!isset($this->npcs[$name])) {
            return "NPC '$name' doesn't exist!";
        }

        switch ($setting) {
            case "look":
                if (!isset($args[2]) || !in_array($args[2], ["true", "false"])) {
                    return "Usage: /npc set $name look <true|false>";
                }
                $this->npcs[$name]["look"] = ($args[2] === "true");
                $this->save();
                if (isset($this->spawnedNPCs[$name])) {
                    $eid = $this->spawnedNPCs[$name];
                    if (isset($this->npcEntityData[$eid])) {
                        $this->npcEntityData[$eid]["look"] = $this->npcs[$name]["look"];
                    }
                }
                return "NPC '$name' look set to " . $args[2];

            case "hold":
                if (count($args) < 3) {
                    return "Usage: /npc set $name hold <item_id>";
                }
                $id = intval($args[2]);
                $this->npcs[$name]["hold"] = $id;
                $this->save();
                if (isset($this->spawnedNPCs[$name])) {
                    $this->doRespawnNPC($name);
                }
                return "NPC '$name' hold item set to $id";

            case "command":
                if (count($args) < 3) {
                    return "Usage: /npc set $name command <command>\nUse {player} for player name.";
                }
                $command = implode(" ", array_slice($args, 2));
                $this->npcs[$name]["command"] = $command;
                $this->save();
                if (isset($this->spawnedNPCs[$name])) {
                    $eid = $this->spawnedNPCs[$name];
                    if (isset($this->npcEntityData[$eid])) {
                        $this->npcEntityData[$eid]["command"] = $command;
                    }
                }
                return "NPC '$name' command set to: $command";

            case "crouched":
                if (!isset($args[2]) || !in_array($args[2], ["true", "false"])) {
                    return "Usage: /npc set $name crouched <true|false>";
                }
                $this->npcs[$name]["crouched"] = ($args[2] === "true");
                $this->save();
                if (isset($this->spawnedNPCs[$name])) {
                    $this->doRespawnNPC($name);
                }
                return "NPC '$name' crouched set to " . $args[2];

            case "pos":
                if (!($issuer instanceof Player)) {
                    return "Use this in-game!";
                }
                $this->npcs[$name]["x"] = round($issuer->entity->x, 2);
                $this->npcs[$name]["y"] = round($issuer->entity->y, 2);
                $this->npcs[$name]["z"] = round($issuer->entity->z, 2);
                $this->npcs[$name]["level"] = $issuer->entity->level->getName();
                $this->save();
                if (isset($this->spawnedNPCs[$name])) {
                    $this->doRespawnNPC($name);
                }
                return "NPC '$name' position updated.";

            default:
                return "Unknown setting. Available: look, hold, command, crouched, pos";
        }
    }

    private function doRespawnNPC($name) {
        if (isset($this->spawnedNPCs[$name])) {
            $oldEid = $this->spawnedNPCs[$name];
            $entity = $this->api->entity->get($oldEid);
            if ($entity) {
                $entity->close();
            }
            unset($this->spawnedNPCs[$name]);
            unset($this->npcEntityData[$oldEid]);
        }
        $this->spawnSingleNPC($name);
    }

    public function cmdSpawnNPC($args, $issuer) {
        if (!($issuer instanceof Player)) {
            return "Please use this command in-game!";
        }

        if (count($args) < 1) {
            return "Usage: /npc spawn <name>";
        }

        $name = $args[0];

        if (!isset($this->npcs[$name])) {
            return "NPC '$name' doesn't exist! Create it first with /npc create $name";
        }

        if (isset($this->spawnedNPCs[$name])) {
            $entity = $this->api->entity->get($this->spawnedNPCs[$name]);
            if ($entity && $entity->closed !== true && $entity->dead !== true) {
                return "NPC '$name' is already spawned! Use /npc respawn $name";
            }
            $oldEid = $this->spawnedNPCs[$name];
            unset($this->spawnedNPCs[$name]);
            unset($this->npcEntityData[$oldEid]);
        }

        $this->npcs[$name]["x"] = round($issuer->entity->x, 2);
        $this->npcs[$name]["y"] = round($issuer->entity->y, 2);
        $this->npcs[$name]["z"] = round($issuer->entity->z, 2);
        $this->npcs[$name]["level"] = $issuer->entity->level->getName();
        $this->save();

        if ($this->spawnSingleNPC($name)) {
            return "NPC '$name' spawned at your position!";
        }
        return "Failed to spawn NPC '$name'";
    }

    public function respawnNPC($args) {
        if (count($args) < 1) {
            return "Usage: /npc respawn <name|all>";
        }

        $name = $args[0];

        if ($name === "all") {
            foreach ($this->spawnedNPCs as $n => $eid) {
                $entity = $this->api->entity->get($eid);
                if ($entity) {
                    $entity->close();
                }
                unset($this->npcEntityData[$eid]);
            }
            $this->spawnedNPCs = [];
            $this->spawnAllNPCs();
            return "All NPCs respawned!";
        }

        if (!isset($this->npcs[$name])) {
            return "NPC '$name' doesn't exist!";
        }

        $this->doRespawnNPC($name);
        return "NPC '$name' respawned!";
    }

    public function listNPCs() {
        if (count($this->npcs) === 0) {
            return "No NPCs created.";
        }
        $output = "NPCs:\n";
        foreach ($this->npcs as $name => $data) {
            $status = "Not spawned";
            if (isset($this->spawnedNPCs[$name])) {
                $entity = $this->api->entity->get($this->spawnedNPCs[$name]);
                if ($entity && $entity->closed !== true && $entity->dead !== true) {
                    $status = "Spawned";
                } else {
                    $status = "Lost";
                }
            }
            $cmd = !empty($data["command"]) ? $data["command"] : "none";
            $look = ($data["look"] ?? false) ? "yes" : "no";
            $output .= "- $name [$status] Look:$look Cmd:$cmd\n";
        }
        return $output;
    }

    public function removeNPC($args) {
        if (count($args) < 1) {
            return "Usage: /npc remove <name>";
        }
        $name = $args[0];
        if (!isset($this->npcs[$name])) {
            return "NPC '$name' doesn't exist!";
        }
        if (isset($this->spawnedNPCs[$name])) {
            $eid = $this->spawnedNPCs[$name];
            $entity = $this->api->entity->get($eid);
            if ($entity) {
                $entity->close();
            }
            unset($this->spawnedNPCs[$name]);
            unset($this->npcEntityData[$eid]);
        }

        unset($this->npcs[$name]);
        $this->save();
        return "NPC '$name' removed!";
    }

    public function __destruct() {
        $this->save();
    }
}
