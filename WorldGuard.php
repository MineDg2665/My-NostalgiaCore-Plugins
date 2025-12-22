<?php
/* 
__PocketMine Plugin__
name=WorldGuard
description=Plugin for managing private regions.
version=1.3
author=MineDg
class=WorldGuard
apiversion=12.1
*/
/* 
1.3 * Added subregions
1.2 * Added flags 
1.1 * Bug Fix 
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
            name TEXT PRIMARY KEY,
            owner TEXT,
            members TEXT,
            world TEXT,
            x1 INTEGER,
            y1 INTEGER,
            z1 INTEGER,
            x2 INTEGER,
            y2 INTEGER,
            z2 INTEGER,
            parent TEXT DEFAULT NULL,
            pvp INTEGER DEFAULT 1,
            use INTEGER DEFAULT 1,
            break INTEGER DEFAULT 1,
            FOREIGN KEY (parent) REFERENCES regions(name) ON DELETE SET NULL
        );");
        
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_parent ON regions(parent);");
    }
    
    public function init() {
        $this->api->console->register("rg", "[subcmd] ...", array($this, "command"));
        $this->api->console->alias("region", "rg");
        $this->api->addHandler("player.block.touch", array($this, "handleBlockTouch"), 10);
        $this->api->addHandler("player.block.place", array($this, "handleBlockPlace"), 10);
        $this->api->addHandler("player.block.break", array($this, "handleBlockBreak"), 10);
        $this->api->addHandler("player.interact", array($this, "handlePlayerInteract"), 10);
        $this->api->addHandler("player.attack", array($this, "handlePlayerAttack"), 10);
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
                case 'info':
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
                case 'setparent':
                    return $this->setParentCommand($issuer, $params);
                case 'remparent':
                case 'removeparent':
                    return $this->removeParentCommand($issuer, $params);
                case 'children':
                    return $this->listChildrenCommand($issuer, $params);
                case 'at':
                case 'pos':
                case 'where':
                    return $this->regionsAtCommand($issuer, $params);
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
                           "/rg info [region] - Get region info.\n".
                           "/rg flag <region> <flag> <value> - Set a flag for a region.\n".
                           "/rg setparent <child> <parent> - Set parent region.\n".
                           "/rg removeparent <region> - Remove parent from region.\n".
                           "/rg children <region> - List child regions.\n".
                           "/rg at - Show all regions at your position.";
            }
        }
        return "Command not recognized.";
    }
    
    private function setParentCommand(Player $issuer, $params) {
        if (count($params) < 2) {
            return "Usage: /rg setparent <child_region> <parent_region>";
        }
        
        $childName = SQLite3::escapeString(array_shift($params));
        $parentName = SQLite3::escapeString(array_shift($params));
        
        $childResult = $this->db->query("SELECT * FROM regions WHERE name = '$childName';");
        $child = $childResult->fetchArray(SQLITE3_ASSOC);
        
        $parentResult = $this->db->query("SELECT * FROM regions WHERE name = '$parentName';");
        $parent = $parentResult->fetchArray(SQLITE3_ASSOC);
        
        if (!$child) {
            return "Child region '$childName' not found.";
        }
        
        if (!$parent) {
            return "Parent region '$parentName' not found.";
        }
        
        if ($child['owner'] !== $issuer->username) {
            return "You are not the owner of region '$childName'.";
        }
        
        if ($parent['owner'] !== $issuer->username) {
            return "You are not the owner of region '$parentName'.";
        }
        
        if ($child['world'] !== $parent['world']) {
            return "Regions must be in the same world.";
        }
        
        if ($child['x1'] < $parent['x1'] || $child['x2'] > $parent['x2'] ||
            $child['y1'] < $parent['y1'] || $child['y2'] > $parent['y2'] ||
            $child['z1'] < $parent['z1'] || $child['z2'] > $parent['z2']) {
            return "Child region must be completely inside parent region.";
        }
        
        if ($this->hasCircularDependency($childName, $parentName)) {
            return "Cannot set parent: circular dependency detected.";
        }
        
        $this->db->exec("UPDATE regions SET parent = '$parentName' WHERE name = '$childName';");
        
        return "Parent of '$childName' set to '$parentName'.";
    }
    
    private function removeParentCommand(Player $issuer, $params) {
        if (count($params) < 1) {
            return "Usage: /rg removeparent <region>";
        }
        
        $regionName = SQLite3::escapeString(array_shift($params));
        
        $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionName';");
        $region = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$region) {
            return "Region '$regionName' not found.";
        }
        
        if ($region['owner'] !== $issuer->username) {
            return "You are not the owner of this region.";
        }
        
        if (!$region['parent']) {
            return "Region '$regionName' doesn't have a parent.";
        }
        
        $this->db->exec("UPDATE regions SET parent = NULL WHERE name = '$regionName';");
        
        return "Parent removed from region '$regionName'.";
    }
    
    private function listChildrenCommand(Player $issuer, $params) {
        if (count($params) < 1) {
            return "Usage: /rg children <region>";
        }
        
        $regionName = SQLite3::escapeString(array_shift($params));
        
        $result = $this->db->query("SELECT * FROM regions WHERE parent = '$regionName';");
        $children = [];
        
        while ($child = $result->fetchArray(SQLITE3_ASSOC)) {
            $children[] = $child['name'] . " (Owner: " . $child['owner'] . ")";
        }
        
        if (count($children) > 0) {
            return "Children of '$regionName':\n" . implode("\n", $children);
        } else {
            return "Region '$regionName' has no children.";
        }
    }
    
    private function hasCircularDependency($childName, $parentName) {
        $current = $parentName;
        while ($current) {
            if ($current === $childName) {
                return true;
            }
            
            $result = $this->db->query("SELECT parent FROM regions WHERE name = '$current';");
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $current = $row ? $row['parent'] : null;
        }
        
        return false;
    }
    
    private function getRegionAtPosition($x, $y, $z, $world) {
        $result = $this->db->query("SELECT * FROM regions WHERE world = '$world';");
        
        $allRegions = [];
        while ($region = $result->fetchArray(SQLITE3_ASSOC)) {
            $allRegions[] = $region;
        }
        
        if (empty($allRegions)) {
            return null;
        }
        
        $containingRegions = [];
        foreach ($allRegions as $region) {
            $minX = min($region['x1'], $region['x2']);
            $maxX = max($region['x1'], $region['x2']);
            $minY = min($region['y1'], $region['y2']);
            $maxY = max($region['y1'], $region['y2']);
            $minZ = min($region['z1'], $region['z2']);
            $maxZ = max($region['z1'], $region['z2']);
            
            if ($x >= $minX && $x <= $maxX &&
                $y >= $minY && $y <= $maxY &&
                $z >= $minZ && $z <= $maxZ) {
                $containingRegions[] = $region;
            }
        }
        
        if (empty($containingRegions)) {
            return null;
        }
        
        if (count($containingRegions) === 1) {
            return $containingRegions[0];
        }
        
        $deepestRegion = null;
        $deepestDepth = -1;
        
        foreach ($containingRegions as $region) {
            $depth = 0;
            $currentRegion = $region;
            
            while ($currentRegion['parent']) {
                $depth++;
                $parentFound = false;
                foreach ($allRegions as $r) {
                    if ($r['name'] === $currentRegion['parent']) {
                        $currentRegion = $r;
                        $parentFound = true;
                        break;
                    }
                }
                if (!$parentFound) {
                    break;
                }
            }
            
            if ($depth > $deepestDepth) {
                $deepestDepth = $depth;
                $deepestRegion = $region;
            }
            elseif ($depth === $deepestDepth && $deepestRegion) {
                $currentVolume = $this->getRegionVolume($region);
                $deepestVolume = $this->getRegionVolume($deepestRegion);
                if ($currentVolume < $deepestVolume) {
                    $deepestRegion = $region;
                }
            }
        }
        
        return $deepestRegion ?: $containingRegions[0];
    }
    
    private function getRegionVolume($region) {
        $width = abs($region['x2'] - $region['x1']);
        $height = abs($region['y2'] - $region['y1']);
        $depth = abs($region['z2'] - $region['z1']);
        return $width * $height * $depth;
    }
    
    private function regionsAtCommand(Player $issuer, $params) {
        $playerPosition = $issuer->entity;
        $x = $playerPosition->x;
        $y = $playerPosition->y;
        $z = $playerPosition->z;
        $world = $playerPosition->level->getName();
        
        $result = $this->db->query("SELECT * FROM regions WHERE world = '$world';");
        $allRegions = [];
        while ($region = $result->fetchArray(SQLITE3_ASSOC)) {
            $allRegions[] = $region;
        }
        
        $containingRegions = [];
        foreach ($allRegions as $region) {
            $minX = min($region['x1'], $region['x2']);
            $maxX = max($region['x1'], $region['x2']);
            $minY = min($region['y1'], $region['y2']);
            $maxY = max($region['y1'], $region['y2']);
            $minZ = min($region['z1'], $region['z2']);
            $maxZ = max($region['z1'], $region['z2']);
            
            if ($x >= $minX && $x <= $maxX &&
                $y >= $minY && $y <= $maxY &&
                $z >= $minZ && $z <= $maxZ) {
                $containingRegions[] = $region;
            }
        }
        
        if (empty($containingRegions)) {
            return "No regions at your position.";
        }
        
        $output = "Regions at your position (" . round($x) . ", " . round($y) . ", " . round($z) . "):\n";
        foreach ($containingRegions as $region) {
            $parentInfo = $region['parent'] ? " (Parent: " . $region['parent'] . ")" : "";
            $volume = $this->getRegionVolume($region);
            $output .= "- " . $region['name'] . $parentInfo . " [Volume: " . $volume . "]\n";
        }
        
        $deepest = $this->getRegionAtPosition($x, $y, $z, $world);
        if ($deepest) {
            $output .= "\nActive region (deepest): " . $deepest['name'];
        }
        
        return $output;
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
                
                $result = $this->db->query("SELECT * FROM regions WHERE world = '$worldName';");
                $overlappingRegions = [];
                $insideRegions = [];
                
                while ($existingRegion = $result->fetchArray(SQLITE3_ASSOC)) {
                    $minX = min($existingRegion['x1'], $existingRegion['x2']);
                    $maxX = max($existingRegion['x1'], $existingRegion['x2']);
                    $minY = min($existingRegion['y1'], $existingRegion['y2']);
                    $maxY = max($existingRegion['y1'], $existingRegion['y2']);
                    $minZ = min($existingRegion['z1'], $existingRegion['z2']);
                    $maxZ = max($existingRegion['z1'], $existingRegion['z2']);
                    
                    if (!($x2 < $minX || $x1 > $maxX ||
                          $y2 < $minY || $y1 > $maxY ||
                          $z2 < $minZ || $z1 > $maxZ)) {
                        
                        if ($x1 >= $minX && $x2 <= $maxX &&
                            $y1 >= $minY && $y2 <= $maxY &&
                            $z1 >= $minZ && $z2 <= $maxZ) {
                            $insideRegions[] = $existingRegion['name'];
                            continue;
                        }
                        
                        $overlappingRegions[] = $existingRegion['name'];
                    }
                }
                
                if (!empty($overlappingRegions)) {
                    return "Region overlaps with existing regions: " . implode(", ", $overlappingRegions);
                }
                
                $regionNameEscaped = SQLite3::escapeString($regionName);
                $ownerEscaped = SQLite3::escapeString($issuer->username);
                $worldEscaped = SQLite3::escapeString($worldName);
                
                $this->db->exec("INSERT INTO regions (name, owner, members, world, x1, y1, z1, x2, y2, z2, pvp, use, break) VALUES ('$regionNameEscaped', '$ownerEscaped', '', '$worldEscaped', $x1, $y1, $z1, $x2, $y2, $z2, 1, 1, 1);");
                
                $message = "Region '$regionName' claimed in world '$worldName'.";
                if (!empty($insideRegions)) {
                    $message .= "\nRegion is inside: " . implode(", ", $insideRegions);
                    $message .= "\nUse /rg setparent $regionName <parent> to set parent.";
                }
                
                return $message;
            } else {
                return "Please set positions 1 and 2.";
            }
        }
        return "Usage: /rg claim <region>";
    }
    
    private function removeRegion($issuer, $params) {
        $regionName = array_shift($params);
        if ($regionName) {
            $regionNameEscaped = SQLite3::escapeString($regionName);
            $ownerEscaped = SQLite3::escapeString($issuer->username);
            
            $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionNameEscaped';");
            $region = $result->fetchArray(SQLITE3_ASSOC);
            
            if (!$region) {
                return "Region '$regionName' not found.";
            }
            
            if ($region['owner'] !== $issuer->username) {
                return "You are not the owner of this region.";
            }
            
            $childrenResult = $this->db->query("SELECT COUNT(*) as count FROM regions WHERE parent = '$regionNameEscaped';");
            $children = $childrenResult->fetchArray(SQLITE3_ASSOC);
            
            if ($children['count'] > 0) {
                return "Cannot remove region '$regionName' because it has child regions. Remove children first.";
            }
            
            $this->db->exec("DELETE FROM regions WHERE name = '$regionNameEscaped' AND owner = '$ownerEscaped';");
            return "Region '$regionName' removed.";
        }
        return "Usage: /rg remove <region>";
    }
    
    public function infoCommand($issuer, $params) {
        $regionName = array_shift($params);
        if ($regionName) {
            $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionName';");
            $region = $result->fetchArray(SQLITE3_ASSOC);
            if ($region) {
                $parentInfo = $region['parent'] ? "Parent: {$region['parent']}\n" : "Parent: None\n";
                $flags = "Flags: pvp=" . ($region['pvp'] ? "true" : "false") . ", use=" . ($region['use'] ? "true" : "false") . ", break=" . ($region['break'] ? "true" : "false");
                return "Region: {$region['name']}\nOwner: {$region['owner']}\nMembers: {$region['members']}\nWorld: {$region['world']}\nCoordinates: ({$region['x1']}, {$region['y1']}, {$region['z1']}) - ({$region['x2']}, {$region['y2']}, {$region['z2']})\n$parentInfo$flags";
            } else {
                return "Region '$regionName' not found.";
            }
        } else {
            $playerPosition = $issuer->entity;
            $region = $this->getRegionAtPosition(
                $playerPosition->x, 
                $playerPosition->y, 
                $playerPosition->z, 
                $playerPosition->level->getName()
            );
            
            if ($region) {
                $parentInfo = $region['parent'] ? "Parent: {$region['parent']}\n" : "";
                $flags = "Flags: pvp=" . ($region['pvp'] ? "true" : "false") . ", use=" . ($region['use'] ? "true" : "false") . ", break=" . ($region['break'] ? "true" : "false");
                return "You are in region: {$region['name']}\nOwner: {$region['owner']}\nMembers: {$region['members']}\nWorld: {$region['world']}\n$parentInfo$flags";
            } else {
                return "You are not in any region.";
            }
        }
    }
    
    private function flagCommand(Player $issuer, $params) {
        if (count($params) < 3) {
            return "Usage: /rg flag <region> <flag> <value>";
        }
        
        $regionName = array_shift($params);
        $flag = strtolower(array_shift($params));
        $value = strtolower(array_shift($params));
        
        $validFlags = ["pvp", "use", "break"];
        $validValues = ["true", "false", "on", "off", "1", "0"];
        
        if (!in_array($flag, $validFlags)) {
            return "Invalid flag. Valid flags: pvp, use, break.";
        }
        
        if (!in_array($value, $validValues)) {
            return "Invalid value. Use true/false, on/off, or 1/0.";
        }
        
        $intValue = ($value === "true" || $value === "on" || $value === "1") ? 1 : 0;
        
        $regionNameEscaped = SQLite3::escapeString($regionName);
        $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionNameEscaped';");
        $region = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$region) {
            return "Region '$regionName' not found.";
        }
        
        if ($region['owner'] !== $issuer->username) {
            return "You are not the owner of this region.";
        }
        
        $flagEscaped = SQLite3::escapeString($flag);
        $this->db->exec("UPDATE regions SET \"$flagEscaped\" = $intValue WHERE name = '$regionNameEscaped';");
        
        return "Flag '$flag' set to ".($intValue ? "true" : "false")." for region '$regionName'.";
    }
    
    private function setPos1($issuer) {
        $this->pos1 = [round($issuer->entity->x)-0.5, round($issuer->entity->y)-1, round($issuer->entity->z)-0.5];
        return "Position 1 set to " . (round($issuer->entity->x) - 0.5) . ", " . (round($issuer->entity->y) - 1) . ", " . (round($issuer->entity->z) - 0.5) . ".";
    }
    
    private function setPos2($issuer) {
        $this->pos2 = [round($issuer->entity->x)-0.5, round($issuer->entity->y)-1, round($issuer->entity->z)-0.5];
        return "Position 2 set to " . (round($issuer->entity->x) - 0.5) . ", " . (round($issuer->entity->y) - 1) . ", " . (round($issuer->entity->z) - 0.5) . ".";
    }
    
    private function listRegions($issuer) {
        $worldName = $issuer->entity->level->getName();
        $result = $this->db->query("SELECT * FROM regions WHERE world = '$worldName';");
        $regions = [];
        while ($region = $result->fetchArray(SQLITE3_ASSOC)) {
            $parentInfo = $region['parent'] ? " (Parent: " . $region['parent'] . ")" : "";
            $regions[] = $region['name'] . $parentInfo;
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
        return true;
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
    
    public function handlePlayerInteract($data, $event) {
        $player = $data["player"];
        $target = $data["target"];
        
        if ($target instanceof Block) {
            $region = $this->getRegionAtPosition($target->x, $target->y, $target->z, $player->level->getName());
            
            if ($region) {
                if ($region['owner'] !== $player->username && !in_array($player->username, explode(',', $region['members']))) {
                    if (!$region['use']) {
                        $player->sendChat("You do not have permission to interact with blocks in this region.");
                        return false;
                    }
                }
            }
        }
        return true;
    }
    
    public function handlePlayerAttack($data, $event) {
        $player = $data["player"];
        $target = $data["target"];
        
        if ($target instanceof Player) {
            $region1 = $this->getRegionAtPosition($player->x, $player->y, $player->z, $player->level->getName());
            $region2 = $this->getRegionAtPosition($target->x, $target->y, $target->z, $target->level->getName());
            
            if ($region1 && $region2) {
                if ($region1['name'] === $region2['name']) {
                    if (!$region1['pvp']) {
                        $player->sendChat("PVP is disabled in this region.");
                        return false;
                    }
                }
            }
        }
        return true;
    }
    
    public function __destruct() {
        $this->db->close();
    }
}
