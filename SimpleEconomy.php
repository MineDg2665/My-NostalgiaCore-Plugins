<?php

/*
__PocketMine Plugin__
name=SimpleEconomy
description=Simple economy plugin
version=1.0
author=MineDg
class=SimpleEconomy
apiversion=12.1,12.2
*/

class SimpleEconomy implements Plugin{
    private $api;
    private $db;
    private $currency = "$";
    private $config;
    private $balances = [];

    public function __construct(ServerAPI $api, $server = false){
        $this->api = $api;
    }

    public function init(){
        $path = $this->api->plugin->configPath($this);
        if(!file_exists($path)){
            mkdir($path, 0777, true);
        }

        $defaultConfig = [
            "currency" => "$",
            "transfer-commission" => 0,
            "min-transfer" => 10,
            "max-transfer" => 10000,
            "pay-for-ore" => false,
            "pay-for-peaceful-mob" => false,
            "pay-for-hostile-mob" => false,
            "pay-amount-min" => 3,
            "pay-amount-max" => 10
        ];

        $configFile = $path . "config.yml";
        if(!file_exists($configFile)){
            $this->api->plugin->writeYAML($configFile, $defaultConfig);
        }
        $this->config = new Config($configFile, CONFIG_YAML, $defaultConfig);

        $this->currency = $this->config->get("currency");

        $dbFile = $path . "balances.sqlite3";
        $this->db = new SQLite3($dbFile);
        $this->db->exec("CREATE TABLE IF NOT EXISTS balances (username TEXT PRIMARY KEY, balance INTEGER DEFAULT 0);");
        $this->db->exec("CREATE TABLE IF NOT EXISTS history (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT, receiver TEXT, amount INTEGER, time INTEGER);");

        $this->loadBalances();

        $this->api->console->register("wallet", "Wallet commands", [$this, "commandHandler"]);
        $this->api->console->register("balance", "Show balance command", [$this, "balanceCommand"]);
        $this->api->console->register("money", "Show balance command", [$this, "balanceCommand"]);
        $this->api->console->register("pay", "Pay another player", [$this, "payCommand"]);

        $this->api->addHandler("player.block.break", [$this, "onBlockBreak"], 15);
        $this->api->addHandler("entity.death", [$this, "onEntityDeath"], 15);

        $this->api->ban->cmdWhitelist("wallet");
    }

    private function loadBalances(){
        $result = $this->db->query("SELECT username, balance FROM balances;");
        if($result === false) return;
        while($row = $result->fetchArray(SQLITE3_ASSOC)){
            $this->balances[strtolower($row["username"])] = (int)$row["balance"];
        }
    }

    private function saveBalance($username){
        $username = strtolower($username);
        $balance = isset($this->balances[$username]) ? $this->balances[$username] : 0;
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO balances (username, balance) VALUES (:username, :balance);");
        $stmt->bindValue(":username", $username, SQLITE3_TEXT);
        $stmt->bindValue(":balance", $balance, SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function addHistory($sender, $receiver, $amount){
        $stmt = $this->db->prepare("INSERT INTO history (sender, receiver, amount, time) VALUES (:sender, :receiver, :amount, :time);");
        $stmt->bindValue(":sender", strtolower($sender), SQLITE3_TEXT);
        $stmt->bindValue(":receiver", strtolower($receiver), SQLITE3_TEXT);
        $stmt->bindValue(":amount", $amount, SQLITE3_INTEGER);
        $stmt->bindValue(":time", time(), SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function getBalance($username){
        $username = strtolower($username);
        if(!isset($this->balances[$username])){
            $this->balances[$username] = 0;
            $this->saveBalance($username);
        }
        return $this->balances[$username];
    }

    private function setBalance($username, $amount){
        $username = strtolower($username);
        $amount = max(0, (int)$amount);
        $this->balances[$username] = $amount;
        $this->saveBalance($username);
    }

    private function addBalance($username, $amount){
        $username = strtolower($username);
        $amount = (int)$amount;
        if($amount <= 0) return false;
        $current = $this->getBalance($username);
        $this->balances[$username] = $current + $amount;
        $this->saveBalance($username);
        return true;
    }

    private function reduceBalance($username, $amount){
        $username = strtolower($username);
        $amount = (int)$amount;
        if($amount <= 0) return false;
        $current = $this->getBalance($username);
        if($current < $amount) return false;
        $this->balances[$username] = $current - $amount;
        $this->saveBalance($username);
        return true;
    }

    private function isOp($username){
        return $this->api->ban->isOp($username);
    }

    public function commandHandler($cmd, $params, $issuer, $alias){
        if(count($params) < 1){
            return $this->walletHelp();
        }

        $sub = strtolower(array_shift($params));
        $isOp = ($issuer instanceof Player) ? $this->isOp($issuer->username) : true;

        switch($sub){
            case "setcurrency":
                if(!$isOp){
                    return "You do not have permission to use this command.";
                }
                if(count($params) < 1){
                    return "Usage: /wallet setcurrency <symbol>";
                }
                $symbol = $params[0];
                $this->currency = $symbol;
                $this->config->set("currency", $symbol);
                $this->config->save();
                return "[SimpleEconomy] Currency symbol set to '$symbol'";

            case "history":
                if(count($params) < 1){
                    return "Usage: /wallet history <player>";
                }
                $targetName = $params[0];
                if(!$isOp && $issuer instanceof Player && strtolower($issuer->username) !== strtolower($targetName)){
                    return "[SimpleEconomy] You can only view your own history.";
                }
                return $this->getHistory($targetName);

            case "help":
                return $this->walletHelp();

            case "give":
                if(!$isOp){
                    return "You do not have permission to use this command.";
                }
                if(count($params) < 2){
                    return "Usage: /wallet give <player> <amount>";
                }
                $playerName = $params[0];
                $amount = (int)$params[1];
                if($amount <= 0){
                    return "[SimpleEconomy] Amount must be positive.";
                }
                $this->addBalance($playerName, $amount);
                return "[SimpleEconomy] Gave $amount {$this->currency} to $playerName.";

            case "take":
                if(!$isOp){
                    return "You do not have permission to use this command.";
                }
                if(count($params) < 2){
                    return "Usage: /wallet take <player> <amount>";
                }
                $playerName = $params[0];
                $amount = (int)$params[1];
                if($amount <= 0){
                    return "[SimpleEconomy] Amount must be positive.";
                }
                if(!$this->reduceBalance($playerName, $amount)){
                    return "[SimpleEconomy] $playerName does not have enough funds.";
                }
                return "[SimpleEconomy] Took $amount {$this->currency} from $playerName.";

            default:
                return "Unknown subcommand. Use /wallet help";
        }
    }

    private function walletHelp(){
        $help = "SimpleEconomy commands:\n";
        $help .= "/wallet setcurrency <symbol> - Set currency symbol (OP only)\n";
        $help .= "/wallet history <player> - Show transfer history\n";
        $help .= "/wallet help - Show this help\n";
        $help .= "/wallet give <player> <amount> - Give money to player (OP only)\n";
        $help .= "/wallet take <player> <amount> - Take money from player (OP only)\n";
        $help .= "/balance or /money <player> - Show player balance\n";
        $help .= "/pay <player> <amount> - Pay money to another player\n";
        return $help;
    }

    public function balanceCommand($cmd, $params, $issuer, $alias){
        $targetName = null;
        if(count($params) < 1){
            if($issuer instanceof Player){
                $targetName = $issuer->username;
            }else{
                return "Usage: /$cmd <player>";
            }
        }else{
            $targetName = $params[0];
        }

        $isOp = ($issuer instanceof Player) ? $this->isOp($issuer->username) : true;
        if(!$isOp && $issuer instanceof Player && strtolower($issuer->username) !== strtolower($targetName)){
            return "[SimpleEconomy] You can only view your own balance.";
        }

        $balance = $this->getBalance($targetName);
        return "$targetName has $balance {$this->currency}.";
    }

    public function payCommand($cmd, $params, $issuer, $alias){
        if(!($issuer instanceof Player)){
            return "This command can only be used in-game.";
        }
        if(count($params) < 2){
            return "Usage: /pay <player> <amount>";
        }
        $sender = $issuer->username;
        $receiver = $params[0];
        $amount = (int)$params[1];

        if($amount <= 0){
            return "[SimpleEconomy] Amount must be positive.";
        }
        if(strtolower($sender) === strtolower($receiver)){
            return "[SimpleEconomy] You cannot pay yourself.";
        }

        $minTransfer = $this->config->get("min-transfer", 10);
        $maxTransfer = $this->config->get("max-transfer", 10000);
        if($amount < $minTransfer){
            return "[SimpleEconomy] Minimum transfer amount is $minTransfer.";
        }
        if($amount > $maxTransfer){
            return "[SimpleEconomy] Maximum transfer amount is $maxTransfer.";
        }

        $commissionPercent = $this->config->get("transfer-commission", 0);
        $commission = (int) ceil($amount * $commissionPercent / 100);
        $totalCost = $amount + $commission;

        $senderBalance = $this->getBalance($sender);
        if($senderBalance < $totalCost){
            return "[SimpleEconomy] You do not have enough funds. You need $totalCost {$this->currency} including commission.";
        }

        $this->reduceBalance($sender, $totalCost);
        $this->addBalance($receiver, $amount);
        $this->addHistory($sender, $receiver, $amount);

        $msg = "[SimpleEconomy] You paid $amount {$this->currency} to $receiver.";
        if($commission > 0){
            $msg .= "[SimpleEconomy] Commission: $commission {$this->currency}.";
        }
        $issuer->sendChat($msg);

        $receiverPlayer = $this->api->player->get($receiver);
        if($receiverPlayer instanceof Player){
            $receiverPlayer->sendChat("[SimpleEconomy] You received $amount {$this->currency} from $sender.");
        }

        return true;
    }

    private function getHistory($username){
        $username = strtolower($username);
        $stmt = $this->db->prepare("SELECT sender, receiver, amount, time FROM history WHERE sender = :user OR receiver = :user ORDER BY time DESC LIMIT 10;");
        $stmt->bindValue(":user", $username, SQLITE3_TEXT);
        $result = $stmt->execute();
        if($result === false){
            return "[SimpleEconomy] No history found.";
        }
        $lines = [];
        while($row = $result->fetchArray(SQLITE3_ASSOC)){
            $timeStr = date("Y-m-d H:i", $row["time"]);
            $sender = $row["sender"];
            $receiver = $row["receiver"];
            $amount = $row["amount"];
            if($username === $sender){
                $lines[] = "[SimpleEconomy] Sent $amount {$this->currency} to $receiver at $timeStr";
            }else{
                $lines[] = "[SimpleEconomy] Received $amount {$this->currency} from $sender at $timeStr";
            }
        }
        if(empty($lines)){
            return "No history found for $username.";
        }
        return implode("\n", $lines);
    }

    public function onBlockBreak($data, $event){
        if($event !== "player.block.break") return;

        $player = $data["player"];
        if(!($player instanceof Player)) return;

        if(!$this->config->get("pay-for-ore", false)) return;

        $block = $data["target"];
        $oreIds = [14, 15, 16, 21, 56, 73, 129]; // Gold, Iron, Coal, Lapis, Diamond, Redstone, Emerald

        if(in_array($block->getID(), $oreIds)){
            $amount = mt_rand($this->config->get("pay-amount-min", 3), $this->config->get("pay-amount-max", 10));
            $this->addBalance($player->username, $amount);
            $player->sendChat("You received $amount {$this->currency} for mining ore.");
        }
    }

    public function onEntityDeath($data, $event){
        if($event !== "entity.death") return;

        $entity = $data["entity"];
        $killer = $data["killer"] ?? null;
        if(!($killer instanceof Player)) return;

        $peacefulIds = [10, 11, 12, 13, 31, 32, 33, 34, 35]; // Peaceful mob IDs (pig, sheep, cow, chicken, etc.)
        $hostileIds = [50, 51, 52, 53, 54, 55, 56, 57, 58]; // Hostile mob IDs (zombie, skeleton, creeper, etc.)

        $entityId = $entity->getID();

        if(in_array($entityId, $peacefulIds) && $this->config->get("pay-for-peaceful-mob", false)){
            $amount = mt_rand($this->config->get("pay-amount-min", 3), $this->config->get("pay-amount-max", 10));
            $this->addBalance($killer->username, $amount);
            $killer->sendChat("[SimpleEconomy] You received $amount {$this->currency} for killing a peaceful mob.");
        }elseif(in_array($entityId, $hostileIds) && $this->config->get("pay-for-hostile-mob", false)){
            $amount = mt_rand($this->config->get("pay-amount-min", 3), $this->config->get("pay-amount-max", 10));
            $this->addBalance($killer->username, $amount);
            $killer->sendChat("[SimpleEconomy] You received $amount {$this->currency} for killing a hostile mob.");
        }
    }

    public function __destruct(){
        if($this->db !== null){
            $this->db->close();
        }
    }
}