<?php

/*
__PocketMine Plugin__
name=AutoWorldsUnloader
description=Automatically unloads worlds
version=1.1
author=MineDg
class=AutoWorldsUnloader
apiversion=12.1
*/

class AutoUnloader implements Plugin {
    private $api;
    private $config;
    private $path;
    private $checkInterval;
    private $protectedWorlds;

    public function __construct(ServerAPI $api, $server = false) {
        $this->api = $api;
    }

    public function init() {
        $this->createConfig();
        $this->loadConfig();
        $this->api->schedule($this->checkInterval * 20, array($this, "checkWorlds"), array(), true);
        $this->api->addHandler("player.quit", array($this, "onPlayerQuit"), 15);
    }

    private function createConfig() {
        $this->path = $this->api->plugin->configPath($this);
        if (!file_exists($this->path)) {
            mkdir($this->path, 0755, true);
        }
        
        $configPath = $this->path . "config.yml";
        if (!file_exists($configPath)) {
            $defaultConfig = array(
                "check-interval" => 60,
                "protected-worlds" => array(
                    "world",
                ),
                "unload-messages" => true
            );
            yaml_emit_file($configPath, $defaultConfig);
        }
    }

    private function loadConfig() {
        $configPath = $this->path . "config.yml";
        $this->config = yaml_parse_file($configPath);
        
        $this->checkInterval = $this->config["check-interval"];
        $this->protectedWorlds = $this->config["protected-worlds"];
    }

    public function checkWorlds() {
        $levels = $this->api->level->getLevels();
        
        foreach ($levels as $level) {
            $worldName = $level->getName();

            if (in_array($worldName, $this->protectedWorlds)) {
                continue;
            }

            if ($level === $this->api->level->getDefault()) {
                continue;
            }
            
            $players = $level->getPlayers();
            
            if (count($players) == 0) {
                $this->unloadWorld($level);
            }
        }
    }

    private function unloadWorld($level) {
        $worldName = $level->getName();
        
        if (count($level->getPlayers()) > 0) {
            return false;
        }
        
        $level->save();
        if ($this->api->level->unloadLevel($level)) {
            if ($this->config["unload-messages"]) {
                console("[AutoWorldsUnloader] World '$worldName' has been unloaded!");
            }
            return true;
        }
        return false;
    }

    public function onPlayerQuit($data, $event) {
        $this->api->schedule(20, array($this, "checkWorlds"), array(), false);
    }

    public function __destruct() {}
}
