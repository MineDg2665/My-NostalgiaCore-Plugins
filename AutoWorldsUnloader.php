<?php

/*
__PocketMine Plugin__
name=AutoWorldsUnloader
description=Automatically unloads worlds
version=1.2
author=MineDg
class=AutoWorldsUnloader
apiversion=12.1
*/

class AutoWorldsUnloader implements Plugin {
    private $api;
    private $config;
    private $path;

    public function __construct(ServerAPI $api, $server = false) {
        $this->api = $api;
    }

    public function init() {
        $this->path = $this->api->plugin->configPath($this);
        $this->config = new Config($this->path . "config.yml", CONFIG_YAML, array(
            "check-interval" => 60,
            "protected-worlds" => array("world"),
            "unload-messages" => true
        ));

        $interval = (int) $this->config->get("check-interval");
        if ($interval < 10) {
            $interval = 10;
        }

        $this->api->schedule($interval * 20, array($this, "checkWorlds"), array(), true);
        $this->api->addHandler("player.quit", array($this, "onPlayerQuit"), 15);
    }

    public function onPlayerQuit($data) {
        $this->api->schedule(40, array($this, "checkWorlds"), array(), false);
    }

    public function checkWorlds() {
        $defaultLevel = $this->api->level->getDefault();
        $protectedWorlds = $this->config->get("protected-worlds");

        if (!is_array($protectedWorlds)) {
            $protectedWorlds = array("world");
        }

        $levels = $this->api->level->getLevels();
        $toUnload = array();

        foreach ($levels as $level) {
            if ($level === $defaultLevel) {
                continue;
            }

            $worldName = $level->getName();

            if (in_array($worldName, $protectedWorlds)) {
                continue;
            }

            $players = $level->getPlayers();
            if (count($players) === 0) {
                $toUnload[] = $level;
            }
        }

        foreach ($toUnload as $level) {
            $this->unloadWorld($level);
        }
    }

    private function unloadWorld($level) {
        $worldName = $level->getName();

        if (count($level->getPlayers()) > 0) {
            return false;
        }

        $level->save();

        $result = $this->api->level->unloadLevel($level);

        if ($result === true) {
            if ($this->config->get("unload-messages") === true) {
                console("[AutoWorldsUnloader] World '$worldName' has been unloaded.");
            }
            return true;
        }

        return false;
    }

    public function __destruct() {
    }
}
