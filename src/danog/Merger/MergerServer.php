<?php
/**
 * Merger server
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

use function Amp\asyncCall;
use function Amp\Socket\connect;
use function Amp\Socket\listen;
use Amp\Loop;
use Amp\ByteStream\ResourceOutputStream;

class MergerServer extends SharedMerger
{
    protected $settings;
    protected $writers = [];
    protected $connections = [];
    protected $stats = [];

    const STATE_HEADER = 0;
    const STATE_HEADER_CMD = 1;
    const STATE_DATA = 2;

    /**
     * Constructor
     *
     * @param \danog\Merger\Settings $settings
     */
    public function __construct($settings)
    {
        set_error_handler(['\\danog\\Merger\\Exception', 'ExceptionErrorHandler']);
        $this->settings = $settings;
        $this->shared_stats = Stats::getInstance();
        $this->logger = new ResourceOutputStream(fopen('php://stdout', 'r+'));
        $this->logger_periodic = new ResourceOutputStream(fopen('php://stdout', 'r+'));

        Loop::repeat(1000, function () {
            $this->logger_periodic->write(json_encode($this->shared_stats->getSpeeds(), JSON_PRETTY_PRINT));
        });
    }
    public function loop()
    {
        Loop::run([$this, 'loopAsync']);
    }
    public function loopAsync()
    {
        $server = listen($this->settings->getTunnelEndpoint());

        while ($socket = yield $server->accept()) {
            list($address, $port) = explode(':', stream_socket_get_name($socket->getResource(), true));
            $this->writers[$address . '-' . $port] = new SharedSocket($socket);
            $this->stats[$address . '-' . $port] = Stats::getInstance($address . '-' . $port);
            $this->pending_out_payloads[$address . '-' . $port] = new \SplQueue;

            asyncCall([$this, 'handleSharedReads'], $address . '-' . $port, true);
        };
    }

    public function handleClientReads($port)
    {
        $this->logger->write("New $port\n");
        $socket = $this->connections[$port];

        $buffer = fopen('php://memory', 'r+');
        while (null !== $chunk = yield $socket->read()) {
            //$this->logger->write("Sending $port => proxy\n");
            fwrite($buffer, $chunk);
            fseek($buffer, 0);

            yield $this->commonWrite($port, $buffer);
        }
        $this->logger->write("Closing $port\n");
        $this->writers[key($this->writers)]->write(pack('VnC', 0, $port, self::ACTION_DISCONNECT));
    }

}
