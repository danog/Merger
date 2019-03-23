<?php
namespace danog\Merger;

class Settings
{
    /**
     * Addresses from which to connect
     *
     * @var array Addresses
     */
    private $connectFromAddresses = [];
    /**
     * Tunnel endpoint
     *
     * @var string address
     */
    private $tunnelEndpoint;
    /**
     * Connection count per address
     *
     * @var int connectionCount
     */
    private $connectionCount = 5;

    public function addConnectAddress($address)
    {
        $this->connectFromAddresses []= $address;
    }
    public function getConnectFromAddresses()
    {
        return $this->connectFromAddresses;
    }

    public function setTunnelEndpoint($address, $port)
    {
        $this->tunnelEndpoint = "$address:$port";
    }
    public function getTunnelEndpoint()
    {
        return $this->tunnelEndpoint;
    }

    public function setConnectionCount($count)
    {
        $this->connectionCount = $count;
    }
    public function getConnectionCount()
    {
        return $this->connectionCount;
    }
}