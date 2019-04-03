<?php

require 'vendor/autoload.php';

use danog\Merger\Merger;
use danog\Merger\Settings;

$settings = new Settings();
$settings->setTunnelEndpoint('1.pwrtelegram.xyz', 4444);
$settings->addConnectAddress('192.168.1.236');
$client = new Merger($settings);
$client->loop();