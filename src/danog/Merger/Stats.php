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

use Amp\Loop;


class Stats
{
    const MEAN_COUNT = 10;

    private static $instance;
    public static function getInstance($id = null)
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        if ($id === null) {
            return self::$instance;
        }
        return new class(self::$instance, $id) {
            /**
             * Stats instance
             *
             * @var Stats
             */
            private $instance;
            /**
             * ID
             *
             * @var an7
             */
            private $id;
            /**
             * Constructor
             *
             * @param [type] $instance
             * @param [type] $id
             */
            public function __construct($instance, $id)
            {
                $this->instance = $instance;
                $this->id = $id;
                $this->instance->allocate($id);
            }
            /**
             * Stop sending data
             *
             * @param int $started When did the sending start
             * @param int $sent    Amount of data sent
             * 
             * @return void
             */
            public function stopSending($started, $sent)
            {
                $this->instance->stopSending($this->id, $started, $sent);
            }
        };
    }

    private $speeds = [];
    private $settings;
    public function __construct()
    {
        Loop::repeat(1000, (function () {
            foreach ($this->speeds as $elem) {
                $elem->pop();
                $elem->unshift(1);
            }
        })->bindTo($this, get_class($this)));
    }
    public function setSettings($settings)
    {
        $this->settings = $settings;
    }
    public function allocate($ID)
    {
        $this->speeds[$ID] = new \Ds\Deque();
        $this->speeds[$ID]->allocate(self::MEAN_COUNT);
        $this->speeds[$ID]->push(...array_fill(0, $this->speeds[$ID]->capacity(), 1000));
    }
    public function stopSending($ID, $started, $sent)
    {
        $time = microtime(true) - $started;
        $this->speeds[$ID]->pop();
        $this->speeds[$ID]->unshift(($sent * 8) / $time);

    }
    public function getSpeed($ID, $powerOf = 6)
    {
        return $this->speeds[$ID]->sum() / pow(10, $powerOf);
    }
    public function balance($bytes)
    {
        $sum = 0;
        $result = [];

        $maxk = 0;
        $maxv = 0;
        foreach ($this->speeds as $last_key => $elem) {
            $ret = $elem->sum(); 
            $sum += $ret;

            if ($ret > $maxv) {
                $maxv = $ret;
                $maxk = $last_key;
            }

            $result[$last_key] = $ret;
        }
        
        $per_bytes = $bytes / $sum;

        $sum = 0;

        foreach ($result as $key => &$elem) {
            $elem = (int) ($elem * $per_bytes);
            if (!$elem) {
                $this->speeds[$key]->pop();
                $this->speeds[$key]->unshift(1000000);
            }
            $sum += $elem;
        }
        $result[$maxk] -= $sum - $bytes;
        return $result;
    }
    public function getSpeeds($powerOf = 6)
    {
        $result = [];
        $y = 0;
        foreach ($this->settings->getConnectFromAddresses() as $bindto) {
            for ($x = 0; $x < $this->settings->getConnectionCount(); $x++) {
                if (!isset($this->speeds[$y])) continue;
                $result[$bindto.'-'.$y] = $this->speeds[$y]->sum() / pow(10, $powerOf);
                $y++;
            }
        }
        return $result;
    }
}
