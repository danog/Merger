<?php

class Connection
{
    /**
     * Incoming socket connection
     *
     * @var \Amp\Socket
     */
    private $in;
    /**
     * Outgoing socket connections
     *
     * @var array<\Amp\Socket>
     */
    private $out = [];
    /**
     * Settings
     *
     * @var array
     */
    private $settings = [];
    /**
     * Speeds
     *
     * @var array
     */
    private $speeds;
    
    public function __construct($settings, $speeds)
    {
        $this->settings = $settings;
        $this->speeds = $speeds;
    }
}