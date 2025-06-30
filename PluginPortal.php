<?php
/*
__PocketMine Plugin__
name=PluginPortal
description=Download plugins from local repository
version=1.0
author=MineDg
class=PluginPortal
apiversion=12.1
*/

class PluginPortal implements Plugin {
    private $api;
    private $repoUrl = "https://raw.githubusercontent.com/MineDg2665/All-for-Minecraft-PE-0.8.1/main/plugins/";
    private $availablePlugins = [];

    public function __construct(ServerAPI $api, $server = false) {
        $this->api = $api;
        $this->loadAvailablePlugins();
    }

    public function init() {
        $this->api->console->register("pluginportal", "[subcommand] ...", array($this, "command"));
    }

    public function command($cmd, $params, $issuer, $alias) {
        if (count($params) < 1) {
            return $this->usage($cmd);
        }

        $subcmd = strtolower(array_shift($params));

        switch ($subcmd) {
            case "install":
                return $this->installPlugin($params);
            case "list":
                return $this->listPlugins();
            default:
                return $this->usage($cmd);
        }
    }

    private function usage($cmd) {
        return "Usage: /$cmd <install|list> [plugin_name]";
    }

    private function installPlugin($params) {
        if (count($params) < 1) {
            return "Please specify the plugin name.";
        }

        $pluginName = array_shift($params);
        if (!array_key_exists($pluginName, $this->availablePlugins)) {
            return "Plugin '$pluginName' not found in the available list.";
        }

        $pluginFile = $this->availablePlugins[$pluginName];
        $localPluginPath = DATA_PATH . "plugins/" . $pluginFile;

        if (file_exists($localPluginPath)) {
            unlink($localPluginPath);
        }

        $pluginFileUrl = $this->repoUrl . $pluginFile;
        $result = $this->downloadPlugin($pluginFileUrl, $localPluginPath, $pluginName);
        if (strpos($result, 'Failed to download') !== false) {
            return $result;
        }

        return "Plugin $pluginName installed successfully.";
    }

    private function downloadPlugin($pluginFileUrl, $localPluginPath, $pluginName) {
        //console("Attempting to download plugin from: $pluginFileUrl");

        $pluginContent = @file_get_contents($pluginFileUrl);
        if ($pluginContent === false) {
            return "Failed to download plugin from $pluginFileUrl. Please check if the URL is correct.";
        }

        //console("Downloaded content size: " . strlen($pluginContent));

        if (file_put_contents($localPluginPath, $pluginContent) === false) {
            return "Failed to save the plugin to $localPluginPath.";
        }

        return "Plugin $pluginName installed successfully.";
    }

    private function loadAvailablePlugins() {
        $localPlugins = scandir(DATA_PATH . "plugins/");
        foreach ($localPlugins as $file) {
            if (preg_match('/^(.+)\.php$/', $file, $matches)) {
                $pluginName = $matches[1];
                $pluginInfo = $this->getPluginInfo(DATA_PATH . "plugins/" . $file);
                if ($pluginInfo) {
                    $this->availablePlugins[$pluginInfo['name']] = [
                        'file' => $file,
                    ];
                }
            }
        }
    }

    private function getPluginInfo($filePath) {
        $info = [];
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        if (preg_match('/^name=(.+)$/m', $content, $matches)) {
            $info['name'] = trim($matches[1]);
            return $info;
        }

        return null;
    }

    public function listPlugins() {
        if (empty($this->availablePlugins)) {
            return "No available plugins.";
        }

        $output = "Available plugins:\n";
        foreach ($this->availablePlugins as $name => $file) {
            $output .= "- $name\n";
        }

        return $output;
    }

    public function __destruct() {}
}
 