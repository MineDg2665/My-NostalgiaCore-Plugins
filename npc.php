<?php
/*
__PocketMine Plugin__
name=NPC
description=A plugin that adds custom NPCs
version=1.3
author=MineDg
class=NPCMain
apiversion=12.1
*/

/*
1.3       * Client crush fixed
1.1 - 1.2 * Bugs fixed
*/

class NPCMain implements Plugin {
    private $npcs;
    private $filePath;
    private $api;
    private $spawnedNPCs = [];

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
        $this->api->console->register("npc", "<create|set|spawn|list|remove|respawn>", [$this, "handleNPCCommand"]);

        $this->api->schedule(60, [$this, "spawnAllNPCs"], []);
        $this->api->schedule(3, [$this, "lookTick"], [], true);
        $this->api->schedule(20 * 60, [$this, "recheckNPCs"], [], true);
    }

    public function recheckNPCs() {
        foreach ($this->spawnedNPCs as $name => $eid) {
            $entity = $this->api->entity->get($eid);
            if (!$entity || $entity->closed === true || $entity->dead === true) {
                console("[NPC] NPC '$name' disappeared, respawning...");
                unset($this->spawnedNPCs[$name]);
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
                unset($this->spawnedNPCs[$name]);
            }
            $this->spawnSingleNPC($name);
        }
        console("[NPC] Done spawning NPCs");
    }

    private function spawnSingleNPC($name) {
        if (!isset($this->npcs[$name])) return false;

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

        $npcData = [
            "x" => (float) $data["x"],
            "y" => (float) $data["y"],
            "z" => (float) $data["z"],
            "command" => $data["command"] ?? "",
            "look" => $data["look"] ?? false,
            "hold" => $data["hold"] ?? 0,
            "crouched" => $data["crouched"] ?? false,
            "name" => $name,
        ];

        $eid = $this->api->entity->getNextEID();
        $e = new NPCEntity($level, $eid, ENTITY_MOB, MOB_ZOMBIE, $npcData);
        $this->api->entity->addRaw($e);

        $this->spawnedNPCs[$name] = $e->eid;

        $this->api->entity->spawnToAll($e);

        console("[NPC] Spawned NPC '$name' at {$data["x"]}, {$data["y"]}, {$data["z"]} in $levelName");
        return true;
    }

    public function lookTick() {
        foreach ($this->spawnedNPCs as $name => $eid) {
            if (!isset($this->npcs[$name])) continue;
            if (!($this->npcs[$name]["look"] ?? false)) continue;

            $entity = $this->api->entity->get($eid);
            if (!$entity || $entity->closed === true) continue;

            $nearest = $this->findNearestPlayer($entity, 10);
            if ($nearest === null) continue;

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
                if (!$player->eid || $player->spawned !== true) continue;

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
            if (!isset($player->entity) || $player->entity === false) continue;
            if ($player->spawned !== true) continue;
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
        if (!($issuer instanceof Player)) return "Please use this command in-game!";
        if (count($args) < 1) return "Usage: /npc create <name>";

        $name = $args[0];
        if (isset($this->npcs[$name])) return "NPC '$name' already exists!";

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
        if (count($args) < 2) return "Usage: /npc set <name> <look|hold|command|crouched|pos> [value]";

        $name = $args[0];
        $setting = strtolower($args[1]);

        if (!isset($this->npcs[$name])) return "NPC '$name' doesn't exist!";

        switch ($setting) {
            case "look":
                if (!isset($args[2]) || !in_array($args[2], ["true", "false"])) {
                    return "Usage: /npc set $name look <true|false>";
                }
                $this->npcs[$name]["look"] = ($args[2] === "true");
                $this->save();
                return "NPC '$name' look set to " . $args[2];

            case "hold":
                if (count($args) < 3) return "Usage: /npc set $name hold <item_id>";
                $id = intval($args[2]);
                $this->npcs[$name]["hold"] = $id;
                $this->save();
                if (isset($this->spawnedNPCs[$name])) $this->doRespawnNPC($name);
                return "NPC '$name' hold item set to $id";

            case "command":
                if (count($args) < 3) return "Usage: /npc set $name command <command>\nUse {player} for player name.";
                $command = implode(" ", array_slice($args, 2));
                $this->npcs[$name]["command"] = $command;
                $this->save();
                if (isset($this->spawnedNPCs[$name])) {
                    $entity = $this->api->entity->get($this->spawnedNPCs[$name]);
                    if ($entity && $entity instanceof NPCEntity) {
                        $entity->npcData["command"] = $command;
                    }
                }
                return "NPC '$name' command set to: $command";

            case "crouched":
                if (!isset($args[2]) || !in_array($args[2], ["true", "false"])) {
                    return "Usage: /npc set $name crouched <true|false>";
                }
                $this->npcs[$name]["crouched"] = ($args[2] === "true");
                $this->save();
                if (isset($this->spawnedNPCs[$name])) $this->doRespawnNPC($name);
                return "NPC '$name' crouched set to " . $args[2];

            case "pos":
                if (!($issuer instanceof Player)) return "Use this in-game!";
                $this->npcs[$name]["x"] = round($issuer->entity->x, 2);
                $this->npcs[$name]["y"] = round($issuer->entity->y, 2);
                $this->npcs[$name]["z"] = round($issuer->entity->z, 2);
                $this->npcs[$name]["level"] = $issuer->entity->level->getName();
                $this->save();
                if (isset($this->spawnedNPCs[$name])) $this->doRespawnNPC($name);
                return "NPC '$name' position updated.";

            default:
                return "Unknown setting. Available: look, hold, command, crouched, pos";
        }
    }

    private function doRespawnNPC($name) {
        if (isset($this->spawnedNPCs[$name])) {
            $oldEid = $this->spawnedNPCs[$name];
            $entity = $this->api->entity->get($oldEid);
            if ($entity) $entity->close();
            unset($this->spawnedNPCs[$name]);
        }
        $this->spawnSingleNPC($name);
    }

    public function cmdSpawnNPC($args, $issuer) {
        if (!($issuer instanceof Player)) return "Please use this command in-game!";
        if (count($args) < 1) return "Usage: /npc spawn <name>";

        $name = $args[0];
        if (!isset($this->npcs[$name])) return "NPC '$name' doesn't exist!";

        if (isset($this->spawnedNPCs[$name])) {
            $entity = $this->api->entity->get($this->spawnedNPCs[$name]);
            if ($entity && $entity->closed !== true && $entity->dead !== true) {
                return "NPC '$name' is already spawned! Use /npc respawn $name";
            }
            unset($this->spawnedNPCs[$name]);
        }

        $this->npcs[$name]["x"] = round($issuer->entity->x, 2);
        $this->npcs[$name]["y"] = round($issuer->entity->y, 2);
        $this->npcs[$name]["z"] = round($issuer->entity->z, 2);
        $this->npcs[$name]["level"] = $issuer->entity->level->getName();
        $this->save();

        if ($this->spawnSingleNPC($name)) return "NPC '$name' spawned at your position!";
        return "Failed to spawn NPC '$name'";
    }

    public function respawnNPC($args) {
        if (count($args) < 1) return "Usage: /npc respawn <name|all>";

        $name = $args[0];

        if ($name === "all") {
            foreach ($this->spawnedNPCs as $n => $eid) {
                $entity = $this->api->entity->get($eid);
                if ($entity) $entity->close();
            }
            $this->spawnedNPCs = [];
            $this->spawnAllNPCs();
            return "All NPCs respawned!";
        }

        if (!isset($this->npcs[$name])) return "NPC '$name' doesn't exist!";

        $this->doRespawnNPC($name);
        return "NPC '$name' respawned!";
    }

    public function listNPCs() {
        if (count($this->npcs) === 0) return "No NPCs created.";
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
        if (count($args) < 1) return "Usage: /npc remove <name>";
        $name = $args[0];
        if (!isset($this->npcs[$name])) return "NPC '$name' doesn't exist!";
        if (isset($this->spawnedNPCs[$name])) {
            $eid = $this->spawnedNPCs[$name];
            $entity = $this->api->entity->get($eid);
            if ($entity) $entity->close();
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
    public $npcData = [];

    function __construct(Level $level, $eid, $class, $type = 0, $data = []) {
        $saved = self::$despawnMobs;
        self::$despawnMobs = false;
        parent::__construct($level, $eid, $class, MOB_ZOMBIE, $data);
        self::$despawnMobs = $saved;

        if (isset($this->ai) && $this->ai instanceof EntityAI) {
            $this->ai->removeTask("TaskRandomWalk");
            $this->ai->removeTask("TaskLookAround");
            $this->ai->removeTask("TaskSwimming");
            $this->ai->removeTask("TaskAttackPlayer");
        }

        $this->npcData = $data;
        $this->setName($data["name"] ?? "NPC");
        $this->crouched = $data["crouched"] ?? false;
        $this->check = false;
        $this->needsUpdate = false;
        $this->canBeAttacked = true;
        $this->dead = false;
        $this->speedX = 0;
        $this->speedY = 0;
        $this->speedZ = 0;
    }

    public function spawn($player) {
        if (!($player instanceof Player)) {
            $player = $this->server->api->player->get($player);
        }
        if (!$player) return false;
        if ($player->eid === $this->eid) return false;
        if ($this->closed !== false) return false;
        if ($player->level !== $this->level) return false;

        $pk = new AddPlayerPacket();
        $pk->clientID = 0;
        $pk->username = $this->getName();
        $pk->eid = $this->eid;
        $pk->x = $this->x;
        $pk->y = $this->y;
        $pk->z = $this->z;
        $pk->yaw = $this->yaw;
        $pk->pitch = $this->pitch;
        $pk->itemID = $this->npcData["hold"] ?? 0;
        $pk->itemAuxValue = 0;
        $pk->metadata = $this->getMetadata();
        $player->dataPacket($pk);
    }

    public function update($now) {
        $this->lastUpdate = $now;
        return false;
    }

    public function updateBurning() {}

    public function updateEntityMovement() {}

    public function harm($dmg, $cause = "generic", $force = false) {
        if (is_numeric($cause)) {
            $e = $this->server->api->entity->get($cause);
            if ($e !== false && $e->isPlayer() && !empty($this->npcData["command"])) {
                $cmd = str_replace("{player}", $e->player->username, $this->npcData["command"]);
                $this->server->api->console->run($cmd, $e->player);
            }
        }
        return false;
    }

    public function getDrops() {
        return [];
    }

    public function createSaveData() {
        return [
            "id" => 0,
            "Health" => 0,
            "Pos" => [0 => 0, 1 => -100, 2 => 0],
            "Rotation" => [0 => 0, 1 => 0],
            "speedX" => 0,
            "speedY" => 0,
            "speedZ" => 0,
        ];
    }
}ф
