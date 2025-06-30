<?php
/*
__PocketMine Plugin__
name=AutoWorldsUnloader
description=Automatically unloads worlds if there is no player in it
version=1.0
author=MineDg
class=AutoWorldsUnloader
apiversion=12.1
*/

class AutoWorldsUnloader implements Plugin {
    private $api;
    private $config;

    public function __construct(ServerAPI $api, $server = false) {
        $this->api = $api;
    }

    public function init() {
        $this->createConfig();
        $this->api->addHandler("player.quit", [$this, "onPlayerQuit"], 10);
        $this->api->addHandler("player.join", [$this, "onPlayerJoin"], 10);
        $unloadInterval = $this->config->get("unload_interval", 30);
        $this->api->schedule(600, [$this, "unloadWorlds"], [], true);
    }

    private function createConfig() {
        $this->config = new Config($this->api->plugin->configPath($this) . "config.yml", CONFIG_YAML, [
            "excluded_worlds" => [],
            "unload_interval" => 30
        ]);
    }

    public function unloadWorlds() {
        foreach ($this->api->level->levels as $level) {
            if (in_array($level->getName(), $this->config->get("excluded_worlds"))) {
                continue;
            }

            $players = $this->api->player->getAll();
            $inWorld = false;

            foreach ($players as $player) {
                if ($player->level->getName() === $level->getName()) {
                    $inWorld = true;
                    break;
                }
            }

            if (!$inWorld) {
                $levelObject = $this->api->level->get($level->getName());
                if ($levelObject !== null) {
                    $this->api->level->unloadLevel($levelObject);
                    //console("[AutoWorldsUnloader] World '{$level->getName()}' has been unloaded.");
                }
            }
        }
    }

    public function onPlayerQuit($data, $event) {
        $this->unloadWorlds();
    }

    public function onPlayerJoin($data, $event) {
        $this->unloadWorlds();
    }

    public function __destruct() {}
}