<?php
/**
 * Merger client
 *
 * This file is part of Merger.
 * Merger is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * Merger is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with Merger.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2019 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 */
namespace danog\Merger;

class Settings
{
    const ACTION_CONNECT = 0;
    const ACTION_DISCONNECT = 1;
    const ACTION_SYNC = 2;

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