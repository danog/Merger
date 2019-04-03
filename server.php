<?php

require 'vendor/autoload.php';

use danog\Merger\MergerServer;
use danog\Merger\Settings;

$settings = new Settings();
$settings->setTunnelEndpoint('0.0.0.0', 4444);
$server = new MergerServer($settings);
$server->loop();
