<?php
/*
__PocketMine Plugin__
name=CoordinateMessage
description=Show messages when player stepping on coordinates
version=1.5
author=MineDg
class=CoordinateMessage
apiversion=12.1
*/

/*
Changelog:
1.5
* Made messages visible to all players and small changes

1.4
* Entity.move changed to player.move

1.3
* Fixed spam message

1.1-1.2 
* Bug fixes and small changes

1.0
* Plugin created

*/

class CoordinateMessage implements Plugin {
    private $api;
    private $server;
    private $messages;
    private $path;
    private $lastPosition;

    public function __construct(ServerAPI $api, $server = false) {
        $this->api = $api;
        $this->lastPosition = [];
    }

    public function init() {
        $this->path = $this->api->plugin->configPath($this);
        $this->messages = new Config($this->path . "messages.yml", CONFIG_YAML, array());
        
        $this->api->console->register("coordm", "[subcmd] ...", array($this, "command"));
        $this->api->addHandler("player.move", array($this, "onPlayerMove"));
        $this->api->ban->cmdWhitelist('coordm');
        $this->api->console->alias("coordinatemessage", "coordm");
    }

    public function command($cmd, $params, $issuer, $alias) {
        if (!($issuer instanceof Player)) {
            return "This command can only be used in-game.";
        }

        $player = $issuer;
        $subcmd = strtolower(array_shift($params));

        switch ($subcmd) {
            case 'set':
                $messageId = array_shift($params);
                $messageText = implode(' ', $params);
                $this->setMessage($player, $messageId, $messageText);
                return "Message '$messageText' with ID '$messageId' set at your current position.";
            case 'list':
                $messages = $this->getMessages($player);
                if (empty($messages)) {
                    return "You have no messages set.";
                }
                $output = "Your messages:\n";
                foreach ($messages as $messageId => $data) {
                    $output .= "$messageId: {$data['text']} ({$data['x']}, {$data['y']}, {$data['z']}, {$data['level']})\n";
                }
                return $output;
            case 'remove':
                $messageId = array_shift($params);
                if ($this->removeMessage($player, $messageId)) {
                    return "Message with ID '$messageId' removed.";
                } else {
                    return "Message with ID '$messageId' not found.";
                }
            case 'rename':
                $messageId = array_shift($params);
                $newText = implode(' ', $params);
                if ($this->renameMessage($player, $messageId, $newText)) {
                    return "Message with ID '$messageId' updated to '$newText'.";
                } else {
                    return "Message with ID '$messageId' not found.";
                }
            default:
                return $this->usage($cmd);
        }
    }

    private function usage($cmd) {
        return "/coordinatemessage set <id> <text>: Set a message at current position.\n"
             . "/coordinatemessage list: List all your messages.\n"
             . "/coordinatemessage remove <id>: Remove a message.\n"
             . "/coordinatemessage rename <id> <new text>: Rename a message.";
    }

    private function getMessages($player) {
        $data = $this->messages->getAll();
        $playerData = isset($data[$player->username]) ? $data[$player->username] : array();
        return $playerData;
    }
    
    private function getAllMessages() {
        $data = $this->messages->getAll();
        $allMessages = array();
        
        foreach ($data as $username => $userMessages) {
            foreach ($userMessages as $messageId => $messageData) {
                $key = "{$messageData['x']},{$messageData['y']},{$messageData['z']},{$messageData['level']}";
                if (!isset($allMessages[$key])) {
                    $allMessages[$key] = array();
                }
                $allMessages[$key][] = array(
                    'text' => $messageData['text'],
                    'owner' => $username
                );
            }
        }
        
        return $allMessages;
    }

    private function setMessage($player, $messageId, $messageText) {
        $data = $this->messages->getAll();
        $playerData = isset($data[$player->username]) ? $data[$player->username] : array();
        $playerData[$messageId] = array(
            'text' => $messageText,
            'x' => round($player->entity->x),
            'y' => round($player->entity->y),
            'z' => round($player->entity->z),
            'level' => $player->entity->level->getName()
        );
        $data[$player->username] = $playerData;
        $this->messages->setAll($data);
        $this->messages->save();
    }

    private function removeMessage($player, $messageId) {
        $data = $this->messages->getAll();
        $playerData = isset($data[$player->username]) ? $data[$player->username] : array();
        if (!isset($playerData[$messageId])) {
            return false;
        }
        unset($playerData[$messageId]);
        $data[$player->username] = $playerData;
        $this->messages->setAll($data);
        $this->messages->save();
        return true;
    }

    private function renameMessage($player, $messageId, $newText) {
        $data = $this->messages->getAll();
        $playerData = isset($data[$player->username]) ? $data[$player->username] : array();
        if (!isset($playerData[$messageId])) {
            return false;
        }
        $playerData[$messageId]['text'] = $newText;
        $data[$player->username] = $playerData;
        $this->messages->setAll($data);
        $this->messages->save();
        return true;
    }

    public function onPlayerMove($data) {
        $player = $data->player;
		if(!($player instanceof Player)) return;
        
        $x = round($data->x);
        $y = round($data->y);
        $z = round($data->z);
        $level = $player->level->getName();

        $currentPosition = "{$x},{$y},{$z},{$level}";
        $lastPositionKey = $player->iusername;

        if (isset($this->lastPosition[$lastPositionKey]) && $this->lastPosition[$lastPositionKey] === $currentPosition) {
            return;
        }

        $this->lastPosition[$lastPositionKey] = $currentPosition;
        
        $allMessages = $this->getAllMessages();
        $posKey = "{$x},{$y},{$z},{$level}";
        
        if (isset($allMessages[$posKey])) {
            foreach ($allMessages[$posKey] as $message) {
                $player->sendChat($message['text']);
            }
        }
    }

    public function __destruct() {}
}
