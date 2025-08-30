<?php

/*
__PocketMine Plugin__
name=AutoParkour
description=AutoParkour plugin
version=1.0
author=MineDg
class=AutoParkour
apiversion=12.1
*/

class AutoParkour implements Plugin {
    private $api;
    private $parkourBlocks = [];
    private $previousParkourBlocks = [];
    private $scores = [];
    private $worldName = "SPAWN";
    private $baseY = 100;

    public function __construct(ServerAPI $api, $server = false) {
        $this->api = $api;
    }

    public function init() {
        $this->api->console->register("parkour", "Start parkour", [$this, "commandHandler"]);
        $this->api->addHandler("player.move", [$this, "onPlayerMove"]);
        $this->api->addHandler("player.quit", [$this, "onPlayerQuit"]);
        $this->api->ban->cmdWhitelist("parkour");
    }

    public function commandHandler($cmd, $params, $issuer, $alias) {
        if(!($issuer instanceof Player)) return "This command can only be used in-game";

        $playerName = strtolower($issuer->username);
        if(isset($this->parkourBlocks[$playerName])) {
            return "[AutoParkour] You are already in parkour!";
        }

        $level = $issuer->entity->level;
        if(strtolower($level->getName()) !== strtolower($this->worldName)) {
            return "[AutoParkour] You can only start parkour in world {$this->worldName}";
        }

        $startX = floor($issuer->entity->x);
        $startZ = floor($issuer->entity->z);

        $pos = new Position($startX + 0.5, $this->baseY, $startZ + 0.5, $level);
        $this->setParkourBlock($playerName, $pos);

        $this->scores[$playerName] = 0;
        $posTp = new Position($startX + 0.5, $this->baseY + 3, $startZ + 0.5, $level);
        $issuer->teleport($posTp);

        $issuer->sendChat("[AutoParkour] Parkour started! Step on the next gold block. Good luck!");
        return true;
    }

    private function setParkourBlock($playerName, Position $pos = null) {
        $level = $pos !== null ? $pos->level : null;

        if($pos === null) {
            if(!isset($this->parkourBlocks[$playerName])) return;
            $oldPos = $this->parkourBlocks[$playerName];
            $pos = $this->getRandomPositionNear($oldPos);
            $level = $pos->level;
        }

        $goldBlock = BlockAPI::get(41, 0);
        $level->setBlock(new Vector3(floor($pos->x), floor($pos->y), floor($pos->z)), $goldBlock, true, true);

        $this->parkourBlocks[$playerName] = $pos;
    }

    private function getRandomPositionNear(Position $pos) {
        $level = $pos->level;
        $randX = floor($pos->x) + mt_rand(-2, 2);
        $randZ = floor($pos->z) + mt_rand(-2, 2);
        $randY = floor($pos->y) + mt_rand(0, 1);
        return new Position($randX + 0.5, $randY, $randZ + 0.5, $level);
    }

    public function onPlayerMove($data) {
        $player = $data->player;
        if(!($player instanceof Player)) return;

        $playerName = strtolower($player->username);

        if(!isset($this->parkourBlocks[$playerName])) return;

        $pos = $this->parkourBlocks[$playerName];
        $level = $player->entity->level;

        if(strtolower($level->getName()) !== strtolower($this->worldName)) {
            $this->endParkour($player, false);
            return;
        }

        $px = floor($player->entity->x);
        $py = floor($player->entity->y) - 1;
        $pz = floor($player->entity->z);

        if($px === floor($pos->x) && $py === floor($pos->y) && $pz === floor($pos->z)) {
            if(!isset($this->scores[$playerName])) {
                $this->scores[$playerName] = 0;
            }
            $this->scores[$playerName]++;

            if(isset($this->previousParkourBlocks[$playerName])) {
                $prevPos = $this->previousParkourBlocks[$playerName];
                $level->setBlock(new Vector3(floor($prevPos->x), floor($prevPos->y), floor($prevPos->z)), BlockAPI::get(0, 0), true, true);
            }

            $this->previousParkourBlocks[$playerName] = $pos;

            $newPos = $this->getRandomPositionNear($pos);
            $this->setParkourBlock($playerName, $newPos);

            $player->sendChat("[AutoParkour] Score: {$this->scores[$playerName]}");
        }

        if($player->entity->y < $this->baseY - 10) {
            $this->endParkour($player, true);
        }
    }

    private function endParkour(Player $player, $fell) {
        $playerName = strtolower($player->username);

        $score = $this->scores[$playerName] ?? 0;

        if(isset($this->parkourBlocks[$playerName])) {
            $pos = $this->parkourBlocks[$playerName];
            $pos->level->setBlock(new Vector3(floor($pos->x), floor($pos->y), floor($pos->z)), BlockAPI::get(0, 0), true, true);
            unset($this->parkourBlocks[$playerName]);
        }

        if(isset($this->previousParkourBlocks[$playerName])) {
            $prevPos = $this->previousParkourBlocks[$playerName];
            $prevPos->level->setBlock(new Vector3(floor($prevPos->x), floor($prevPos->y), floor($prevPos->z)), BlockAPI::get(0, 0), true, true);
            unset($this->previousParkourBlocks[$playerName]);
        }

        unset($this->scores[$playerName]);

        if($fell) {
            $player->sendChat("[AutoParkour] You lost! Your score: $score");
            $cmd = "wallet give {$player->username} $score";
            $this->api->console->run(strtolower($cmd));
        } else {
            $player->sendChat("[AutoParkour] Parkour ended. Your score: $score");
        }
    }

    public function onPlayerQuit($data, $event) {
        $playerName = strtolower($data->iusername);
        if(isset($this->parkourBlocks[$playerName])) {
            $pos = $this->parkourBlocks[$playerName];
            $pos->level->setBlock(new Vector3($pos->x, $pos->y, $pos->z), BlockAPI::get(0, 0), true, true);
            unset($this->parkourBlocks[$playerName]);
        }
        unset($this->scores[$playerName]);
        unset($this->previousParkourBlocks[$playerName]);
    }

    public function __destruct() {
        foreach($this->parkourBlocks as $pos) {
            $pos->level->setBlock(new Vector3(floor($pos->x), floor($pos->y), floor($pos->z)), BlockAPI::get(0, 0), true, true);
        }
    }
}