<?php

/*
__PocketMine Plugin__
name=PluginPortal
description=Install plugins from GitHub repository
version=1.2
author=MineDg
class=PluginPortal
apiversion=12.1
*/

class PluginPortal implements Plugin {
    private $api;
    private $path;
    private $pluginList = [];
    private $rawBaseUrl = "https://raw.githubusercontent.com/MineDg2665/All-for-Minecraft-PE-0.8.1/refs/heads/main/plugins/";
    private $pluginsPerPage = 10;

    public function __construct(ServerAPI $api, $server = false) {
        $this->api = $api;
    }

    public function init() {
        $this->path = $this->api->plugin->configPath($this);
        $this->api->console->register("pluginportal", "Install plugins from repository", array($this, "commandHandler"));
        $this->api->console->alias("pp", "pluginportal");
        $this->api->ban->cmdWhitelist("pluginportal");
        $this->fetchPluginList();
        console("[INFO] [PluginPortal] Loaded " . count($this->pluginList) . " plugins in repository.");
    }

    private function fetchPluginList() {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
            'http' => [
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36',
            ]
        ]);

        $apiUrl = "https://api.github.com/repos/MineDg2665/All-for-Minecraft-PE-0.8.1/contents/plugins";
        $directoryContents = @file_get_contents($apiUrl, false, $context);

        if($directoryContents === false) {
            console("[WARNING] [PluginPortal] Failed to fetch plugin list from repository.");
            return;
        }

        $files = json_decode($directoryContents, true);

        foreach($files as $file) {
            if(isset($file['name']) && substr($file['name'], -4) === '.php') {
                $pluginContent = @file_get_contents($file['download_url'], false, $context);

                if($pluginContent !== false) {
                    preg_match('/name=([^\n]+)/i', $pluginContent, $nameMatch);
                    preg_match('/version=([^\n]+)/i', $pluginContent, $versionMatch);
                    preg_match('/description=([^\n]+)/i', $pluginContent, $descMatch);

                    $name = isset($nameMatch[1]) ? trim($nameMatch[1]) : pathinfo($file['name'], PATHINFO_FILENAME);
                    $version = isset($versionMatch[1]) ? trim($versionMatch[1]) : "Unknown";
                    $description = isset($descMatch[1]) ? trim($descMatch[1]) : "No description available";

                    $this->pluginList[$file['name']] = [
                        'name' => $name,
                        'version' => $version,
                        'description' => $description,
                        'filename' => $file['name']
                    ];
                }
            }
        }
    }

    private function checkAndUpdatePlugins() {
        $localPlugins = scandir(__DIR__);
        $repoPluginNames = [];
        
        foreach ($this->pluginList as $plugin) {
            $repoPluginNames[$plugin['name']] = $plugin;
        }
        
        foreach ($localPlugins as $localPlugin) {
            if (substr($localPlugin, -4) === '.php' && $localPlugin !== basename(__FILE__)) {
                $localPluginContent = file_get_contents(__DIR__ . '/' . $localPlugin);
                if ($localPluginContent !== false) {
                    preg_match('/name=([^\n]+)/i', $localPluginContent, $nameMatch);
                    
                    if (isset($nameMatch[1])) {
                        $localPluginName = trim($nameMatch[1]);
                        
                        foreach ($repoPluginNames as $repoName => $repoPlugin) {
                            if (strtolower($localPluginName) === strtolower($repoName)) {
                                console("[INFO] [PluginPortal] Found matching plugin: " . $localPluginName);
                                $this->installPlugin($repoName);
                                break;
                            }
                        }
                    }
                }
            }
        }
    }

    public function commandHandler($cmd, $args, $issuer, $alias) {
        $output = "";
        if($issuer instanceof Player) {
            if(!$this->api->ban->isOp($issuer->username)) {
                return "You don't have permission to use this command";
            }
        }
        if(count($args) === 0 || $args[0] === "help") {
            $output .= "PluginPortal Commands:\n";
            $output .= "/pp install <name> - Install a plugin\n";
            $output .= "/pp list [page] - List available plugins\n";
            $output .= "/pp info <name> - Show plugin info\n";
            $output .= "/pp search <term> - Search for plugins\n";
            $output .= "/pp refresh - Refresh plugin list\n";
            $output .= "/pp update - Update all plugins";
            return $output;
        }

        switch($args[0]) {
            case "list":
                $page = isset($args[1]) && is_numeric($args[1]) ? intval($args[1]) : 1;
                return $this->listPlugins($page);

            case "info":
                if(!isset($args[1])) {
                    return "Usage: /pp info <plugin>";
                }
                return $this->showPluginInfo($args[1]);

            case "install":
                if(!isset($args[1])) {
                    return "Usage: /pp install <plugin>";
                }
                return $this->installPlugin($args[1], $issuer);
			
			case "remove":
                if(!isset($args[1])) {
                    return "Usage: /pp remove <plugin>";
                }
                return $this->removePlugin($args[1]);

            case "search":
                if(!isset($args[1])) {
                    return "Usage: /pp search <term>";
                }
                return $this->searchPlugins($args[1]);

            case "refresh":
                $this->pluginList = [];
                $this->fetchPluginList();
                return "Plugin list refreshed. " . count($this->pluginList) . " plugins available.";

            case "update":
                return $this->updateAllPlugins($issuer);

            default:
                return "Unknown command. Type /pp help for help.";
        }
    }

    private function listPlugins($page) {
        $totalPlugins = count($this->pluginList);

        if($totalPlugins === 0) {
            return "No plugins available. Try using /pp refresh to update the list.";
        }

        $totalPages = ceil($totalPlugins / $this->pluginsPerPage);

        if($page < 1) $page = 1;
        if($page > $totalPages) $page = $totalPages;

        $start = ($page - 1) * $this->pluginsPerPage;

        $output = "Plugins (Page $page of $totalPages):\n";

        $i = 0;
        foreach($this->pluginList as $plugin) {
            if($i >= $start && $i < $start + $this->pluginsPerPage) {
                $output .= "- " . $plugin['name'] . " v" . $plugin['version'] . "\n";
            }
            $i++;
        }

        return $output;
    }

    private function showPluginInfo($name) {
        foreach($this->pluginList as $plugin) {
            if(strtolower($plugin['name']) === strtolower($name)) {
                $output = "Plugin: " . $plugin['name'] . "\n";
                $output .= "Version: " . $plugin['version'] . "\n";
                $output .= "Description: " . $plugin['description'] . "\n";
                $output .= "File: " . $plugin['filename'] . "\n";
                $output .= "Use /pp install " . $plugin['name'] . " to install";
                return $output;
            }
        }

        return "Plugin not found. Use /pp list to see available plugins.";
    }

    private function searchPlugins($term) {
        $results = [];
        $term = strtolower($term);

        foreach($this->pluginList as $plugin) {
            if(strpos(strtolower($plugin['name']), $term) !== false || 
               strpos(strtolower($plugin['description']), $term) !== false) {
                $results[] = $plugin;
            }
        }

        if(count($results) === 0) {
            return "No plugins found matching '$term'";
        }

        $output = "Search results for '$term':\n";
        foreach($results as $plugin) {
            $output .= "- " . $plugin['name'] . " v" . $plugin['version'] . "\n";
        }

        $output .= "Use /pp info <name> to get more information";
        return $output;
    }

    private function installPlugin($name) {
        $found = false;
        $plugin = null;
        foreach($this->pluginList as $p) {
            if(strtolower($p['name']) === strtolower($name)) {
                $found = true;
                $plugin = $p;
                break;
            }
        }

        if(!$found) {
            return "Plugin not found. Use /pp list to see available plugins.";
        }

        $localPlugins = scandir(__DIR__);
        foreach ($localPlugins as $localPlugin) {
            if (substr($localPlugin, -4) === '.php' && $localPlugin !== basename(__FILE__)) {
                $localPluginContent = file_get_contents(__DIR__ . '/' . $localPlugin);
                if ($localPluginContent !== false) {
                    preg_match('/name=([^\n]+)/i', $localPluginContent, $nameMatch);
                    
                    if (isset($nameMatch[1]) && strtolower(trim($nameMatch[1])) === strtolower($plugin['name'])) {
                        console("[INFO] [PluginPortal] Removing existing plugin: " . $localPlugin);
                        if (!unlink(__DIR__ . '/' . $localPlugin)) {
                            return "Failed to remove existing plugin: " . $localPlugin;
                        }
                        break;
                    }
                }
            }
        }

        $url = $this->rawBaseUrl . $plugin['filename'];
        $targetPath = __DIR__ . '/' . $plugin['filename'];

        console("[INFO] [PluginPortal] Downloading " . $plugin['name'] . " from " . $url);

        if(!$this->downloadFile($url, $targetPath)) {
            return "Failed to download plugin. Please try again later.";
        }

        console("[INFO] [PluginPortal] Plugin downloaded to " . $targetPath);

        return "Plugin " . $plugin['name'] . " has been downloaded successfully!\nPlease restart your server to load the plugin.";
    }
	
	private function removePlugin($name) {
        $found = false;
        foreach($this->pluginList as $plugin) {
            if(strtolower($plugin['name']) === strtolower($name)) {
                $found = true;
                break;
            }
        }

        if(!$found) {
            return "Plugin not found. Use /pp list to see available plugins.";
        }

        $pluginPath = __DIR__ . '/' . $plugin['filename'];

        if (file_exists($pluginPath)) {
            if (unlink($pluginPath)) {
                console("[INFO] [PluginPortal] Plugin " . $name . " removed successfully.");
                return "Plugin " . $name . " has been removed successfully.";
            } else {
                return "Failed to remove plugin " . $name . ". Please check permissions.";
            }
        } else {
			return "Plugin file does not exist.";
        }
    }
	
    private function updateAllPlugins($issuer) {
        $localPlugins = scandir(__DIR__);
        $updatedCount = 0;
        
        foreach ($localPlugins as $localPlugin) {
            if (substr($localPlugin, -4) === '.php' && $localPlugin !== basename(__FILE__)) {
                $localPluginContent = file_get_contents(__DIR__ . '/' . $localPlugin);
                if ($localPluginContent !== false) {
                    preg_match('/name=([^\n]+)/i', $localPluginContent, $nameMatch);
                    
                    if (isset($nameMatch[1])) {
                        $localPluginName = trim($nameMatch[1]);
                        
                        foreach ($this->pluginList as $repoPlugin) {
                            if (strtolower($localPluginName) === strtolower($repoPlugin['name'])) {
                                console("[INFO] [PluginPortal] Updating plugin: " . $localPluginName);
                                
                                if (!unlink(__DIR__ . '/' . $localPlugin)) {
                                    console("[ERROR] [PluginPortal] Failed to remove existing plugin: " . $localPlugin);
                                    continue;
                                }
                                
                                $url = $this->rawBaseUrl . $repoPlugin['filename'];
                                $targetPath = __DIR__ . '/' . $repoPlugin['filename'];
                                
                                if(!$this->downloadFile($url, $targetPath)) {
                                    console("[ERROR] [PluginPortal] Failed to download plugin: " . $repoPlugin['name']);
                                    continue;
                                }
                                
                                $updatedCount++;
                                console("[INFO] [PluginPortal] Plugin " . $repoPlugin['name'] . " updated successfully.");
                                break;
                            }
                        }
                    }
                }
            }
        }

        if ($updatedCount > 0) {
            return "Updated $updatedCount plugins successfully!\nPlease restart your server to apply the updates.";
        } else {
            return "No plugins were updated. All plugins are up to date.";
        }
    }

    private function downloadFile($url, $targetPath) {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
            'http' => [
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36',
            ]
        ]);

        $content = @file_get_contents($url, false, $context);

        if($content === false) {
            console("[ERROR] [PluginPortal] Failed to download file from: " . $url);
            return false;
        }

        $result = @file_put_contents($targetPath, $content);

        if($result === false) {
            console("[ERROR] [PluginPortal] Failed to write file to: " . $targetPath);
            return false;
        }

        return true;
    }

    public function __destruct() {}
}
