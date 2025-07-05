<?php
/*
__PocketMine Plugin__
name=WorldGuard
description=Plugin for managing private regions
version=1.2
author=MineDg
class=WorldGuard
apiversion=12.1
*/

/*

	1.2
	* Added flags

	1.1
	* Bug Fix
	
*/
class WorldGuard implements Plugin {
    private $api;
    private $db;
    private $pos1;
    private $pos2;
    private $path;

    public function __construct(ServerAPI $api, $server = false) {
        $this->api = $api;
        $this->path = __DIR__ . "/WorldGuard/";
        if (!file_exists($this->path)) {
            mkdir($this->path, 0755, true);
        }
        $this->db = new SQLite3($this->path . "regions.db");
        $this->db->exec("CREATE TABLE IF NOT EXISTS regions (
            name TEXT,
            owner TEXT,
            members TEXT,
            world TEXT,
            x1 INTEGER,
            y1 INTEGER,
            z1 INTEGER,
            x2 INTEGER,
            y2 INTEGER,
            z2 INTEGER,
            pvp INTEGER DEFAULT 1,
            use INTEGER DEFAULT 1,
            break INTEGER DEFAULT 1
        );");
    }

    public function init() {
        $this->api->console->register("rg", "[subcmd] ...", array($this, "command"));
        $this->api->console->alias("region", "rg");
        $this->api->addHandler("player.block.touch", array($this, "handleBlockTouch"), 10);
        $this->api->addHandler("player.block.place", array($this, "handleBlockPlace"), 10);
        $this->api->addHandler("player.block.break", array($this, "handleBlockBreak"), 10);
        $this->api->ban->cmdWhitelist("rg");
    }

    public function command($cmd, $params, $issuer, $alias) {
        if (!($issuer instanceof Player)) {
            return "This command can only be used in-game.";
        }

        if ($cmd == "rg") {
            $subcmd = strtolower(array_shift($params));
            switch ($subcmd) {
                case 'i':
                    return $this->infoCommand($issuer, $params);
                case 'addowner':
                    return $this->addOwnerCommand($issuer, $params);
                case 'addmember':
                    return $this->addMemberCommand($issuer, $params);
                case 'removeowner':
                    return $this->removeOwnerCommand($issuer, $params);
                case 'removemember':
                    return $this->removeMemberCommand($issuer, $params);
                case 'pos1':
                    return $this->setPos1($issuer);
                case 'pos2':
                    return $this->setPos2($issuer);
                case 'claim':
                    return $this->claimRegion($issuer, $params);
                case 'remove':
                    return $this->removeRegion($issuer, $params);
                case 'list':
                    return $this->listRegions($issuer);
                case 'flag':
                    return $this->flagCommand($issuer, $params);
                default:
                    return "/rg claim <region> - Claim a region.\n".
                           "/rg remove <region> - Remove a region.\n".
                           "/rg addmember <region> <player> - Add a member.\n".
                           "/rg addowner <region> <player> - Add an owner.\n".
                           "/rg removemember <region> <player> - Remove a member.\n".
                           "/rg removeowner <region> <player> - Remove an owner.\n".
                           "/rg pos1 - Set the first position.\n".
                           "/rg pos2 - Set the second position.\n".
                           "/rg list - List regions.\n".
                           "/rg flag <region> <flag> <value> - Set a flag for a region.";
            }
        }
        return "Command not recognized.";
    }

    private function flagCommand(Player $issuer, $params) {
        if (count($params) < 3) {
            return "Usage: /rg flag <region> <flag> <value>";
        }

        $regionName = array_shift($params);
        $flag = strtolower(array_shift($params));
        $value = strtolower(array_shift($params));

        $validFlags = ["pvp", "use", "break"];
        $validValues = ["true", "false", "on", "off"];

        if (!in_array($flag, $validFlags)) {
            return "Invalid flag. Valid flags: pvp, use, break.";
        }

        if (!in_array($value, $validValues)) {
            return "Invalid value. Use true/false or on/off.";
        }

        $intValue = ($value === "true" || $value === "on") ? 1 : 0;

        $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionName';");
        $region = $result->fetchArray(SQLITE3_ASSOC);
        if (!$region) {
            return "Region '$regionName' not found.";
        }
        if ($region['owner'] !== $issuer->username) {
            return "You are not the owner of this region.";
        }

        $flagEscaped = SQLite3::escapeString($flag);
		$regionNameEscaped = SQLite3::escapeString($regionName);
		$this->db->exec("UPDATE regions SET \"$flagEscaped\" = $intValue WHERE name = '$regionNameEscaped';");

        return "Flag '$flag' set to ".($intValue ? "true" : "false")." for region '$regionName'.";
    }

    public function infoCommand($issuer, $params) {
        $regionName = array_shift($params);
        if ($regionName) {
            $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionName';");
            $region = $result->fetchArray(SQLITE3_ASSOC);
            if ($region) {
                $flags = "Flags: pvp=" . ($region['pvp'] ? "true" : "false") .
                         ", use=" . ($region['use'] ? "true" : "false") .
                         ", break=" . ($region['break'] ? "true" : "false");
                return "Region: {$region['name']}, Owner: {$region['owner']}, Members: {$region['members']}, World: {$region['world']}, Coordinates: ({$region['x1']}, {$region['y1']}, {$region['z1']}) - ({$region['x2']}, {$region['y2']}, {$region['z2']})\n$flags";
            } else {
                return "Region '$regionName' not found.";
            }
        } else {
            $playerPosition = $issuer->entity;
            $result = $this->db->query("SELECT * FROM regions WHERE world = '{$playerPosition->level->getName()}' AND x1 <= {$playerPosition->x} AND x2 >= {$playerPosition->x} AND y1 <= {$playerPosition->y} AND y2 >= {$playerPosition->y} AND z1 <= {$playerPosition->z} AND z2 >= {$playerPosition->z};");
            $region = $result->fetchArray(SQLITE3_ASSOC);
            if ($region) {
                $flags = "Flags: pvp=" . ($region['pvp'] ? "true" : "false") .
                         ", use=" . ($region['use'] ? "true" : "false") .
                         ", break=" . ($region['break'] ? "true" : "false");
                return "You are in region: {$region['name']}, Owner: {$region['owner']}, Members: {$region['members']}, World: {$region['world']}.\n$flags";
            } else {
                return "You are not in any region.";
            }
        }
    }

    private function setPos1($issuer) {
        $this->pos1 = [round($issuer->entity->x)-0.5, round($issuer->entity->y)-1, round($issuer->entity->z)-0.5];
        return "Position 1 set in " . (round($issuer->entity->x) - 0.5) . ", " . (round($issuer->entity->y) - 1) . ", " . (round($issuer->entity->z) - 0.5) . ".";
    }

    private function setPos2($issuer) {
        $this->pos2 = [round($issuer->entity->x)-0.5, round($issuer->entity->y)-1, round($issuer->entity->z)-0.5];
        return "Position 2 set in " . (round($issuer->entity->x) - 0.5) . ", " . (round($issuer->entity->y) - 1) . ", " . (round($issuer->entity->z) - 0.5) . ".";
    }

    private function claimRegion($issuer, $params) {
        $regionName = array_shift($params);
        $worldName = $issuer->entity->level->getName();
        if ($regionName) {
            if (isset($this->pos1) && isset($this->pos2)) {
                $x1 = min($this->pos1[0], $this->pos2[0]);
                $y1 = min($this->pos1[1], $this->pos2[1]);
                $z1 = min($this->pos1[2], $this->pos2[2]);
                $x2 = max($this->pos1[0], $this->pos2[0]);
                $y2 = max($this->pos1[1], $this->pos2[1]);
                $z2 = max($this->pos1[2], $this->pos2[2]);

                $this->db->exec("INSERT INTO regions (name, owner, members, world, x1, y1, z1, x2, y2, z2, pvp, use, break) VALUES ('$regionName', '{$issuer->username}', '', '$worldName', $x1, $y1, $z1, $x2, $y2, $z2, 1, 1, 1);");
                return "Region '$regionName' claimed in world '$worldName'.";
            } else {
                return "Please set positions 1 and 2.";
            }
        }
        return "Usage: /rg claim <region>";
    }

    private function removeRegion($issuer, $params) {
        $regionName = array_shift($params);
        if ($regionName) {
            $this->db->exec("DELETE FROM regions WHERE name = '$regionName' AND owner = '{$issuer->username}';");
            return "Region '$regionName' removed.";
        }
        return "Usage: /rg remove <region>";
    }

    private function listRegions($issuer) {
        $worldName = $issuer->entity->level->getName();
        $result = $this->db->query("SELECT * FROM regions WHERE world = '$worldName';");
        $regions = [];
        while ($region = $result->fetchArray(SQLITE3_ASSOC)) {
            $regions[] = $region['name'];
        }
        return count($regions) > 0 ? "Regions in this world: " . implode(", ", $regions) : "No regions found in this world.";
    }

    private function addOwnerCommand($issuer, $params) {
        $regionName = array_shift($params);
        $newOwner = array_shift($params);
        if ($regionName && $newOwner) {
            $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionName';");
            $region = $result->fetchArray(SQLITE3_ASSOC);
            if ($region && $region['owner'] === $issuer->username) {
                $members = explode(',', $region['members']);
                if (!in_array($newOwner, $members) && $newOwner !== $region['owner']) {
                    $members[] = $newOwner;
                    $this->db->exec("UPDATE regions SET members = '" . implode(',', $members) . "' WHERE name = '$regionName';");
                    return "Added $newOwner as an owner to region '$regionName'.";
                } else {
                    return "$newOwner is already an owner or member of this region.";
                }
            } else {
                    return "You are not the owner of this region or the region does not exist.";
            }
        }
        return "Usage: /rg addowner <region> <player>";
    }

    private function removeOwnerCommand($issuer, $params) {
        $regionName = array_shift($params);
        $ownerToRemove = array_shift($params);
        if ($regionName && $ownerToRemove) {
            $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionName';");
            $region = $result->fetchArray(SQLITE3_ASSOC);
            if ($region && $region['owner'] === $issuer->username) {
                $members = explode(',', $region['members']);
                if (in_array($ownerToRemove, $members)) {
                    $members = array_diff($members, [$ownerToRemove]);
                    $this->db->exec("UPDATE regions SET members = '" . implode(',', $members) . "' WHERE name = '$regionName';");
                    return "Removed $ownerToRemove from owners of region '$regionName'.";
                } else {
                    return "$ownerToRemove is not a member of this region.";
                }
            } else {
                return "You are not the owner of this region or the region does not exist.";
            }
        }
        return "Usage: /rg removeowner <region> <player>";
    }

    private function addMemberCommand($issuer, $params) {
        $regionName = array_shift($params);
        $newMember = array_shift($params);
        if ($regionName && $newMember) {
            $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionName';");
            $region = $result->fetchArray(SQLITE3_ASSOC);
            if ($region && $region['owner'] === $issuer->username) {
                $members = explode(',', $region['members']);
                if (!in_array($newMember, $members)) {
                    $members[] = $newMember;
                    $this->db->exec("UPDATE regions SET members = '" . implode(',', $members) . "' WHERE name = '$regionName';");
                    return "Added $newMember as a member to region '$regionName'.";
                } else {
                    return "$newMember is already a member of this region.";
                }
            } else {
                return "You are not the owner of this region or the region does not exist.";
            }
        }
        return "Usage: /rg addmember <region> <player>";
    }

    private function removeMemberCommand($issuer, $params) {
        $regionName = array_shift($params);
        $memberToRemove = array_shift($params);
        if ($regionName && $memberToRemove) {
            $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionName';");
            $region = $result->fetchArray(SQLITE3_ASSOC);
            if ($region && $region['owner'] === $issuer->username) {
                $members = explode(',', $region['members']);
                if (in_array($memberToRemove, $members)) {
                    $members = array_diff($members, [$memberToRemove]);
                    $this->db->exec("UPDATE regions SET members = '" . implode(',', $members) . "' WHERE name = '$regionName';");
                    return "Removed $memberToRemove from members of region '$regionName'.";
                } else {
                    return "$memberToRemove is not a member of this region.";
                }
            } else {
                return "You are not the owner of this region or the region does not exist.";
            }
        }
        return "Usage: /rg removemember <region> <player>";
    }

    public function handleBlockTouch($data, $event) {
        $player = $data["player"];
        $target = $data["target"];
        $region = $this->getRegionAtPosition($target->x, $target->y, $target->z, $player->level->getName());

        if ($region) {
            if ($region['owner'] !== $player->username && !in_array($player->username, explode(',', $region['members']))) {
                if (!$region['use']) {
                    $player->sendChat("You do not have permission to use blocks in this region.");
                    return false;
                }
            }
        }
    }

    public function handleBlockPlace($data, $event) {
        $player = $data["player"];
        $target = $data["target"];
        $region = $this->getRegionAtPosition($target->x, $target->y, $target->z, $player->level->getName());

        if ($region) {
            if ($region['owner'] !== $player->username && !in_array($player->username, explode(',', $region['members']))) {
                if (!$region['break']) {
                    $player->sendChat("You do not have permission to place blocks in this region.");
                    return false;
                }
            }
        }
        return true;
    }

    public function handleBlockBreak($data, $event) {
        $player = $data["player"];
        $target = $data["target"];
        $region = $this->getRegionAtPosition($target->x, $target->y, $target->z, $player->level->getName());

        if ($region) {
            if ($region['owner'] !== $player->username && !in_array($player->username, explode(',', $region['members']))) {
                if (!$region['break']) {
                    $player->sendChat("You do not have permission to break blocks in this region.");
                    return false;
                }
            }
        }
        return true;
    }

    private function checkRegionPermission(Player $player, Block $target, $action) {
        $region = $this->getRegionAtPosition($target->x, $target->y, $target->z, $player->level->getName());

        if ($region) {
            if ($region['owner'] !== $player->username && !in_array($player->username, explode(',', $region['members']))) {
                $player->sendChat("You do not have permission to $action in this region.");
                return false;
            }
        }
        return true;
    }

    private function getRegionAtPosition($x, $y, $z, $world) {
        $result = $this->db->query("SELECT * FROM regions WHERE world = '$world' AND x1 <= $x AND x2 >= $x AND y1 <= $y AND y2 >= $y AND z1 <= $z AND z2 >= $z;");
        return $result->fetchArray(SQLITE3_ASSOC);
    }

    public function __destruct() {
        $this->db->close();
    }
}
