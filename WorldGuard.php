<?php
/* 
__PocketMine Plugin__
name=WorldGuard
description=Plugin for managing private regions.
version=1.4
author=MineDg
class=WorldGuard
apiversion=12.1
*/

/* 
1.4 * Some fixes
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
    }
    
    public function init() {
        $this->path = $this->api->plugin->configPath($this);
        
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
            use_flag INTEGER DEFAULT 0,
            break_flag INTEGER DEFAULT 0,
            FOREIGN KEY (parent) REFERENCES regions(name) ON DELETE SET NULL
        );");
        
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_parent ON regions(parent);");
        
        $this->api->console->register("rg", "[subcmd] ...", array($this, "command"));
        $this->api->console->alias("region", "rg");
        $this->api->addHandler("player.block.touch", array($this, "handleBlockTouch"), 0);
        $this->api->addHandler("player.block.place", array($this, "handleBlockPlace"), 0);
        $this->api->addHandler("player.block.break", array($this, "handleBlockBreak"), 0);
        $this->api->addHandler("player.interact", array($this, "handlePlayerInteract"), 0);
        $this->api->addHandler("player.attack", array($this, "handlePlayerAttack"), 0);
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
    
    private function setPos1($issuer) {
        $x = (int) floor($issuer->entity->x);
        $y = (int) floor($issuer->entity->y) - 1;
        $z = (int) floor($issuer->entity->z);
        $this->pos1 = [$x, $y, $z];
        return "Position 1 set to $x, $y, $z.";
    }
    
    private function setPos2($issuer) {
        $x = (int) floor($issuer->entity->x);
        $y = (int) floor($issuer->entity->y) - 1;
        $z = (int) floor($issuer->entity->z);
        $this->pos2 = [$x, $y, $z];
        return "Position 2 set to $x, $y, $z.";
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
        
        if (!$child) return "Child region '$childName' not found.";
        if (!$parent) return "Parent region '$parentName' not found.";
        if ($child['owner'] !== $issuer->username) return "You are not the owner of region '$childName'.";
        if ($parent['owner'] !== $issuer->username) return "You are not the owner of region '$parentName'.";
        if ($child['world'] !== $parent['world']) return "Regions must be in the same world.";
        
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
        
        if (!$region) return "Region '$regionName' not found.";
        if ($region['owner'] !== $issuer->username) return "You are not the owner of this region.";
        if (!$region['parent']) return "Region '$regionName' doesn't have a parent.";
        
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
        }
        return "Region '$regionName' has no children.";
    }
    
    private function hasCircularDependency($childName, $parentName) {
        $current = $parentName;
        $visited = [];
        while ($current) {
            if ($current === $childName) return true;
            if (isset($visited[$current])) return true;
            $visited[$current] = true;
            
            $escaped = SQLite3::escapeString($current);
            $result = $this->db->query("SELECT parent FROM regions WHERE name = '$escaped';");
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $current = $row ? $row['parent'] : null;
        }
        return false;
    }
    
    private function getAllRegionsInWorld($world) {
        $worldEscaped = SQLite3::escapeString($world);
        $result = $this->db->query("SELECT * FROM regions WHERE world = '$worldEscaped';");
        $allRegions = [];
        while ($region = $result->fetchArray(SQLITE3_ASSOC)) {
            $allRegions[] = $region;
        }
        return $allRegions;
    }
    
    private function isInsideRegion($x, $y, $z, $region) {
        return $x >= $region['x1'] && $x <= $region['x2'] &&
               $y >= $region['y1'] && $y <= $region['y2'] &&
               $z >= $region['z1'] && $z <= $region['z2'];
    }
    
    private function getRegionAtPosition($x, $y, $z, $world) {
        $bx = (int) floor($x);
        $by = (int) floor($y);
        $bz = (int) floor($z);
        
        $allRegions = $this->getAllRegionsInWorld($world);
        
        if (empty($allRegions)) return null;
        
        $containingRegions = [];
        foreach ($allRegions as $region) {
            if ($this->isInsideRegion($bx, $by, $bz, $region)) {
                $containingRegions[] = $region;
            }
        }
        
        if (empty($containingRegions)) return null;
        if (count($containingRegions) === 1) return $containingRegions[0];
        
        $deepestRegion = null;
        $deepestDepth = -1;
        
        foreach ($containingRegions as $region) {
            $depth = $this->getRegionDepth($region, $allRegions);
            
            if ($depth > $deepestDepth) {
                $deepestDepth = $depth;
                $deepestRegion = $region;
            } elseif ($depth === $deepestDepth && $deepestRegion) {
                if ($this->getRegionVolume($region) < $this->getRegionVolume($deepestRegion)) {
                    $deepestRegion = $region;
                }
            }
        }
        
        return $deepestRegion ?: $containingRegions[0];
    }
    
    private function getRegionDepth($region, $allRegions) {
        $depth = 0;
        $current = $region;
        $visited = [];
        
        while ($current['parent']) {
            if (isset($visited[$current['name']])) break;
            $visited[$current['name']] = true;
            $depth++;
            
            $parentFound = false;
            foreach ($allRegions as $r) {
                if ($r['name'] === $current['parent']) {
                    $current = $r;
                    $parentFound = true;
                    break;
                }
            }
            if (!$parentFound) break;
        }
        
        return $depth;
    }
    
    private function getRegionVolume($region) {
        $width = abs($region['x2'] - $region['x1']) + 1;
        $height = abs($region['y2'] - $region['y1']) + 1;
        $depth = abs($region['z2'] - $region['z1']) + 1;
        return $width * $height * $depth;
    }
    
    private function regionsAtCommand(Player $issuer, $params) {
        $x = (int) floor($issuer->entity->x);
        $y = (int) floor($issuer->entity->y);
        $z = (int) floor($issuer->entity->z);
        $world = $issuer->entity->level->getName();
        
        $allRegions = $this->getAllRegionsInWorld($world);
        
        $containingRegions = [];
        foreach ($allRegions as $region) {
            if ($this->isInsideRegion($x, $y, $z, $region)) {
                $containingRegions[] = $region;
            }
        }
        
        if (empty($containingRegions)) {
            return "No regions at your position.";
        }
        
        $output = "Regions at your position ($x, $y, $z):\n";
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
        
        if (!$regionName) {
            return "Usage: /rg claim <region>";
        }
        
        if (!isset($this->pos1) || !isset($this->pos2)) {
            return "Please set positions 1 and 2.";
        }
        
        $x1 = min($this->pos1[0], $this->pos2[0]);
        $y1 = min($this->pos1[1], $this->pos2[1]);
        $z1 = min($this->pos1[2], $this->pos2[2]);
        $x2 = max($this->pos1[0], $this->pos2[0]);
        $y2 = max($this->pos1[1], $this->pos2[1]);
        $z2 = max($this->pos1[2], $this->pos2[2]);
        
        $worldEscaped = SQLite3::escapeString($worldName);
        $result = $this->db->query("SELECT * FROM regions WHERE world = '$worldEscaped';");
        $overlappingRegions = [];
        $insideRegions = [];
        
        while ($existingRegion = $result->fetchArray(SQLITE3_ASSOC)) {
            $eMinX = $existingRegion['x1'];
            $eMaxX = $existingRegion['x2'];
            $eMinY = $existingRegion['y1'];
            $eMaxY = $existingRegion['y2'];
            $eMinZ = $existingRegion['z1'];
            $eMaxZ = $existingRegion['z2'];
            
            if (!($x2 < $eMinX || $x1 > $eMaxX ||
                  $y2 < $eMinY || $y1 > $eMaxY ||
                  $z2 < $eMinZ || $z1 > $eMaxZ)) {
                
                if ($x1 >= $eMinX && $x2 <= $eMaxX &&
                    $y1 >= $eMinY && $y2 <= $eMaxY &&
                    $z1 >= $eMinZ && $z2 <= $eMaxZ) {
                    $insideRegions[] = $existingRegion['name'];
                    continue;
                }
                
                if ($eMinX >= $x1 && $eMaxX <= $x2 &&
                    $eMinY >= $y1 && $eMaxY <= $y2 &&
                    $eMinZ >= $z1 && $eMaxZ <= $z2) {
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
        
        $checkResult = $this->db->query("SELECT name FROM regions WHERE name = '$regionNameEscaped';");
        if ($checkResult->fetchArray()) {
            return "Region '$regionName' already exists.";
        }
        
        $this->db->exec("INSERT INTO regions (name, owner, members, world, x1, y1, z1, x2, y2, z2, pvp, use_flag, break_flag) VALUES ('$regionNameEscaped', '$ownerEscaped', '', '$worldEscaped', $x1, $y1, $z1, $x2, $y2, $z2, 1, 0, 0);");
        
        unset($this->pos1);
        unset($this->pos2);
        
        $volume = ($x2 - $x1 + 1) * ($y2 - $y1 + 1) * ($z2 - $z1 + 1);
        $message = "Region '$regionName' claimed ($x1,$y1,$z1 - $x2,$y2,$z2) Volume: $volume blocks.";
        if (!empty($insideRegions)) {
            $message .= "\nRegion is inside: " . implode(", ", $insideRegions);
            $message .= "\nUse /rg setparent $regionName <parent> to set parent.";
        }
        
        return $message;
    }
    
    private function removeRegion($issuer, $params) {
        $regionName = array_shift($params);
        if (!$regionName) {
            return "Usage: /rg remove <region>";
        }
        
        $regionNameEscaped = SQLite3::escapeString($regionName);
        $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionNameEscaped';");
        $region = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$region) return "Region '$regionName' not found.";
        if ($region['owner'] !== $issuer->username) return "You are not the owner of this region.";
        
        $childrenResult = $this->db->query("SELECT COUNT(*) as count FROM regions WHERE parent = '$regionNameEscaped';");
        $children = $childrenResult->fetchArray(SQLITE3_ASSOC);
        
        if ($children['count'] > 0) {
            return "Cannot remove region '$regionName' because it has child regions. Remove children first.";
        }
        
        $ownerEscaped = SQLite3::escapeString($issuer->username);
        $this->db->exec("DELETE FROM regions WHERE name = '$regionNameEscaped' AND owner = '$ownerEscaped';");
        return "Region '$regionName' removed.";
    }
    
    public function infoCommand($issuer, $params) {
        $regionName = array_shift($params);
        if ($regionName) {
            $regionNameEscaped = SQLite3::escapeString($regionName);
            $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionNameEscaped';");
            $region = $result->fetchArray(SQLITE3_ASSOC);
            if ($region) {
                return $this->formatRegionInfo($region);
            }
            return "Region '$regionName' not found.";
        }
        
        $region = $this->getRegionAtPosition(
            $issuer->entity->x, 
            $issuer->entity->y, 
            $issuer->entity->z, 
            $issuer->entity->level->getName()
        );
        
        if ($region) {
            return "You are in region:\n" . $this->formatRegionInfo($region);
        }
        return "You are not in any region.";
    }
    
    private function formatRegionInfo($region) {
        $parentInfo = $region['parent'] ? "Parent: {$region['parent']}" : "Parent: None";
        $pvp = $region['pvp'] ? "true" : "false";
        $use = $region['use_flag'] ? "true" : "false";
        $brk = $region['break_flag'] ? "true" : "false";
        $volume = $this->getRegionVolume($region);
        
        return "Region: {$region['name']}\n" .
               "Owner: {$region['owner']}\n" .
               "Members: " . ($region['members'] ?: "None") . "\n" .
               "World: {$region['world']}\n" .
               "Coordinates: ({$region['x1']}, {$region['y1']}, {$region['z1']}) - ({$region['x2']}, {$region['y2']}, {$region['z2']})\n" .
               "Volume: $volume blocks\n" .
               "$parentInfo\n" .
               "Flags: pvp=$pvp, use=$use, break=$brk";
    }
    
    private function flagCommand(Player $issuer, $params) {
        if (count($params) < 3) {
            return "Usage: /rg flag <region> <flag> <value>\nValid flags: pvp, use, break";
        }
        
        $regionName = array_shift($params);
        $flag = strtolower(array_shift($params));
        $value = strtolower(array_shift($params));
        
        $flagMap = [
            "pvp" => "pvp",
            "use" => "use_flag",
            "break" => "break_flag"
        ];
        
        if (!isset($flagMap[$flag])) {
            return "Invalid flag. Valid flags: pvp, use, break.";
        }
        
        $validValues = ["true", "false", "on", "off", "1", "0"];
        if (!in_array($value, $validValues)) {
            return "Invalid value. Use true/false, on/off, or 1/0.";
        }
        
        $intValue = ($value === "true" || $value === "on" || $value === "1") ? 1 : 0;
        
        $regionNameEscaped = SQLite3::escapeString($regionName);
        $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionNameEscaped';");
        $region = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$region) return "Region '$regionName' not found.";
        if ($region['owner'] !== $issuer->username) return "You are not the owner of this region.";
        
        $columnName = $flagMap[$flag];
        $this->db->exec("UPDATE regions SET $columnName = $intValue WHERE name = '$regionNameEscaped';");
        
        return "Flag '$flag' set to " . ($intValue ? "true" : "false") . " for region '$regionName'.";
    }
    
    private function listRegions($issuer) {
        $worldName = SQLite3::escapeString($issuer->entity->level->getName());
        $result = $this->db->query("SELECT * FROM regions WHERE world = '$worldName';");
        $regions = [];
        while ($region = $result->fetchArray(SQLITE3_ASSOC)) {
            $parentInfo = $region['parent'] ? " (Parent: " . $region['parent'] . ")" : "";
            $regions[] = $region['name'] . $parentInfo;
        }
        return count($regions) > 0 ? "Regions in this world:\n" . implode("\n", $regions) : "No regions found in this world.";
    }
    
    private function addOwnerCommand($issuer, $params) {
        $regionName = array_shift($params);
        $newOwner = array_shift($params);
        if (!$regionName || !$newOwner) {
            return "Usage: /rg addowner <region> <player>";
        }
        
        $regionNameEscaped = SQLite3::escapeString($regionName);
        $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionNameEscaped';");
        $region = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$region || $region['owner'] !== $issuer->username) {
            return "You are not the owner of this region or the region does not exist.";
        }
        
        $members = array_filter(explode(',', $region['members']));
        if (in_array($newOwner, $members) || $newOwner === $region['owner']) {
            return "$newOwner is already an owner or member of this region.";
        }
        
        $members[] = $newOwner;
        $membersStr = SQLite3::escapeString(implode(',', $members));
        $this->db->exec("UPDATE regions SET members = '$membersStr' WHERE name = '$regionNameEscaped';");
        return "Added $newOwner as an owner to region '$regionName'.";
    }
    
    private function removeOwnerCommand($issuer, $params) {
        $regionName = array_shift($params);
        $ownerToRemove = array_shift($params);
        if (!$regionName || !$ownerToRemove) {
            return "Usage: /rg removeowner <region> <player>";
        }
        
        $regionNameEscaped = SQLite3::escapeString($regionName);
        $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionNameEscaped';");
        $region = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$region || $region['owner'] !== $issuer->username) {
            return "You are not the owner of this region or the region does not exist.";
        }
        
        $members = array_filter(explode(',', $region['members']));
        if (!in_array($ownerToRemove, $members)) {
            return "$ownerToRemove is not a member of this region.";
        }
        
        $members = array_diff($members, [$ownerToRemove]);
        $membersStr = SQLite3::escapeString(implode(',', $members));
        $this->db->exec("UPDATE regions SET members = '$membersStr' WHERE name = '$regionNameEscaped';");
        return "Removed $ownerToRemove from owners of region '$regionName'.";
    }
    
    private function addMemberCommand($issuer, $params) {
        $regionName = array_shift($params);
        $newMember = array_shift($params);
        if (!$regionName || !$newMember) {
            return "Usage: /rg addmember <region> <player>";
        }
        
        $regionNameEscaped = SQLite3::escapeString($regionName);
        $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionNameEscaped';");
        $region = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$region || $region['owner'] !== $issuer->username) {
            return "You are not the owner of this region or the region does not exist.";
        }
        
        $members = array_filter(explode(',', $region['members']));
        if (in_array($newMember, $members)) {
            return "$newMember is already a member of this region.";
        }
        
        $members[] = $newMember;
        $membersStr = SQLite3::escapeString(implode(',', $members));
        $this->db->exec("UPDATE regions SET members = '$membersStr' WHERE name = '$regionNameEscaped';");
        return "Added $newMember as a member to region '$regionName'.";
    }
    
    private function removeMemberCommand($issuer, $params) {
        $regionName = array_shift($params);
        $memberToRemove = array_shift($params);
        if (!$regionName || !$memberToRemove) {
            return "Usage: /rg removemember <region> <player>";
        }
        
        $regionNameEscaped = SQLite3::escapeString($regionName);
        $result = $this->db->query("SELECT * FROM regions WHERE name = '$regionNameEscaped';");
        $region = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$region || $region['owner'] !== $issuer->username) {
            return "You are not the owner of this region or the region does not exist.";
        }
        
        $members = array_filter(explode(',', $region['members']));
        if (!in_array($memberToRemove, $members)) {
            return "$memberToRemove is not a member of this region.";
        }
        
        $members = array_diff($members, [$memberToRemove]);
        $membersStr = SQLite3::escapeString(implode(',', $members));
        $this->db->exec("UPDATE regions SET members = '$membersStr' WHERE name = '$regionNameEscaped';");
        return "Removed $memberToRemove from members of region '$regionName'.";
    }
    
    private function isPlayerAllowed($playerName, $region) {
        if ($region['owner'] === $playerName) return true;
        $members = array_filter(explode(',', $region['members']));
        return in_array($playerName, $members);
    }
    
    public function handleBlockTouch($data, $event) {
        $player = $data["player"];
        $target = $data["target"];
        $region = $this->getRegionAtPosition($target->x, $target->y, $target->z, $player->level->getName());
        
        if ($region && !$this->isPlayerAllowed($player->username, $region)) {
            if (!$region['use_flag']) {
                $player->sendChat("[WorldGuard] You cannot use blocks in region '{$region['name']}'.");
                return false;
            }
        }
        return true;
    }
    
    public function handleBlockPlace($data, $event) {
        $player = $data["player"];
        $target = $data["target"];
        $region = $this->getRegionAtPosition($target->x, $target->y, $target->z, $player->level->getName());
        
        if ($region && !$this->isPlayerAllowed($player->username, $region)) {
            if (!$region['break_flag']) {
                $player->sendChat("[WorldGuard] You cannot place blocks in region '{$region['name']}'.");
                return false;
            }
        }
        return true;
    }
    
    public function handleBlockBreak($data, $event) {
        $player = $data["player"];
        $target = $data["target"];
        $region = $this->getRegionAtPosition($target->x, $target->y, $target->z, $player->level->getName());
        
        if ($region && !$this->isPlayerAllowed($player->username, $region)) {
            if (!$region['break_flag']) {
                $player->sendChat("[WorldGuard] You cannot break blocks in region '{$region['name']}'.");
                return false;
            }
        }
        return true;
    }
    
    public function handlePlayerInteract($data, $event) {
        $player = $data["player"];
        $target = $data["target"];
        
        if ($target instanceof Block) {
            $region = $this->getRegionAtPosition($target->x, $target->y, $target->z, $player->level->getName());
            
            if ($region && !$this->isPlayerAllowed($player->username, $region)) {
                if (!$region['use_flag']) {
                    $player->sendChat("[WorldGuard] You cannot interact in region '{$region['name']}'.");
                    return false;
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
            
            if ($region1 && !$region1['pvp']) {
                $player->sendChat("[WorldGuard] PVP is disabled in region '{$region1['name']}'.");
                return false;
            }
            if ($region2 && !$region2['pvp']) {
                $player->sendChat("[WorldGuard] PVP is disabled in region '{$region2['name']}'.");
                return false;
            }
        }
        return true;
    }
    
    public function __destruct() {
        if (isset($this->db)) {
            $this->db->close();
        }
    }
}
