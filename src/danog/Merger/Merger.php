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

use Amp\ByteStream\ResourceOutputStream;
use Amp\Loop;
use Amp\Socket\ClientConnectContext;
use function Amp\asyncCall;
use function Amp\Socket\connect;
use function Amp\Socket\listen;
use danog\Merger\Abstr\SharedMerger;

class Merger extends SharedMerger
{
    protected $settings;
    protected $writers = [];
    protected $connections = [];
    protected $shared_stats;
    protected $server = false;
    protected $connection_seqno = 3;

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
        $y = 0;
        foreach ($this->settings->getConnectFromAddresses() as $bindto) {
            for ($x = 0; $x < $this->settings->getConnectionCount(); $x++) {
                $context = (new ClientConnectContext())->withBindTo($bindto);
                $id = $y++;
                $this->writers[$id] = new SequentialSocket(yield connect('tcp://' . $this->settings->getTunnelEndpoint(), $context), $id);
                $this->writers[$id]->write($s = pack('n', $id));
                yield $this->writers[$id]->read(2);
                if (fread($this->writers[$id]->getBuffer(), 2) !== $s) {
                    throw new Exception('Wrong reply');
                }
                ksort($this->writers);
                asyncCall([$this, 'sharedLoop'], $id);
            }
        }

        $server = listen("127.0.0.1:55555");

        $loop_callback = $this->getReadLoop();

        while ($socket = yield $server->accept()) {
            $port = explode(':', stream_socket_get_name($socket->getResource(), true))[1];
            $port = $this->connection_seqno++;
            $this->connections[$port] = new MergerWorker($port, $loop_callback, $this->logger, $this->writers);
            $this->connections[$port]->loop(new SequentialSocket($socket));
        };
    }
    public function getReadLoop(): callable
    {
        return function () {
            $this->_logger->write("New {$this->_port}\n");
            $socket = $this->_socket;
            $socksInit = $socket->getBuffer();

            yield $socket->read(2);

            if (fread($socksInit, 1) !== chr(5)) {
                throw new \Exception('Wrong socks5 init ');
            }
            yield $socket->write(chr(5));
            $auth = null;
            for ($x = 0; $x < ord(fread($socksInit, 1)); $x++) {
                yield $socket->read(1);
                $type = ord(fread($socksInit, 1));
                if ($type === 0) {
                    $auth = false;
                } else if ($type === 2) {
                    $auth = true;
                }
            }
            if ($auth === null) {
                throw new \Exception('No socks5 method');
            }
            $authchr = chr($auth ? 2 : 0);
            yield $socket->write($authchr);

            yield $socket->read(3);
            if (fread($socksInit, 3) !== chr(5) . chr(1) . $authchr) {
                throw new \Exception('Wrong socks5 ack');
            }
            if ($auth) {
                yield $socket->read(1);
                $ulen = ord(fread(1));
                yield $socket->read($ulen);
                $username = fread($socksInit, $ulen);

                yield $socket->read(1);
                $plen = ord(fread(1));
                yield $socket->read($plen);
                $password = fread($socksInit, $plen);
            }
            yield $socket->read(1);
            $payload = fread($socksInit, 1);
            switch (ord($payload)) {
                case 0x03:
                    yield $socket->read(1);
                    $payload .= fread($socksInit, 1);
                    $toRead = ord($payload[1]);
                    yield $socket->read($toRead);
                    $payload .= fread($socksInit, $toRead);
                    break;
                case 0x04:
                    $toRead = 16;
                    yield $socket->read($toRead);
                    $payload .= fread($socksInit, $toRead);
                    break;
                case 0x01:
                    $toRead = 4;
                    yield $socket->read($toRead);
                    $payload .= fread($socksInit, $toRead);
                    break;
            }
            yield $socket->read(2);
            $rport = unpack('n', fread($socksInit, 2))[1];

            yield $socket->write(chr(5) . chr(0) . chr(0) . chr(1) . pack('Vn', 0, 0));

            $this->_logger->write("================================ SENDING CONNECT ================================\n");
            yield $this->_writers[key($this->_writers)]->write(pack('VnCn', 0, $this->_port, Settings::ACTION_CONNECT, $rport) . $payload);

            if (fstat($socksInit)['size'] - ftell($socksInit)) {
                yield $this->commonWrite($socksInit);
            }
            while (yield $socket->read()) {
                yield $this->commonWrite($socksInit);
            }
            $this->close();
        };
    }
}
