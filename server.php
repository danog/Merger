<?php

require 'vendor/autoload.php';

use danog\Merger\MergerServer;
use danog\Merger\Settings;

$settings = new Settings();
$settings->setTunnelEndpoint('127.0.0.1', 4444);
$server = new MergerServer($settings);
$server->loop();