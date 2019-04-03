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
namespace danog\Merger\Abstr;

use danog\Merger\MergerWorker;
use danog\Merger\SequentialSocket;
use danog\Merger\Settings;
use function Amp\Socket\connect;
use function Amp\asyncCall;

/**
 * Abstract class shared merger
 */
abstract class SharedMerger
{
    /**
     * Get read loop
     *
     * @return callable
     */
    abstract public function getReadLoop(): callable ;

    /**
     * Shared socket loop
     *
     * @param  int $writerId Writer ID
     * @return void
     */
    public function sharedLoop($writerId)
    {
        $socket = $this->writers[$writerId];
        $buffer = $socket->getBuffer();
        $loop_callback = $this->getReadLoop();

        while (true) {
            if (!yield $socket->read(6)) {
                $this->logger->write("Breaking out of $writerId\n");
                break;
            }

            $length = unpack('V', stream_get_contents($buffer, 4))[1];
            $port = unpack('n', stream_get_contents($buffer, 2))[1];

            if ($length === 0) {
                yield $socket->read(1);
                $cmd = ord(stream_get_contents($buffer, 1));
                $this->logger->write("Reading special action $cmd           $writerId\n");

                if ($cmd === Settings::ACTION_DISCONNECT) {
                    $this->connections[$port]->close();
                    unset($this->connections[$port]);
                } elseif ($cmd === Settings::ACTION_CONNECT && $this->server) {
                    yield $socket->read(3);
                    $rport = unpack('n', stream_get_contents($buffer, 2))[1];
                    $type = ord(stream_get_contents($buffer, 1));
                    switch ($type) {
                        case 0x03:
                            yield $socket->read(1);
                            $toRead = ord(stream_get_contents($buffer, 1));
                            yield $socket->read($toRead);
                            $host = stream_get_contents($buffer, $toRead);
                            break;
                        case 0x04:
                            $toRead = 16;
                            yield $socket->read($toRead);
                            $host = '[' . inet_ntop(stream_get_contents($buffer, $toRead)) . ']';
                            break;
                        case 0x01:
                            $toRead = 4;
                            yield $socket->read($toRead);
                            $host = inet_ntop(stream_get_contents($buffer, $toRead));
                            break;
                    }
                    asyncCall(function () use ($host, $rport, $port, $loop_callback) {
                        $this->logger->write("Connecting to $host:$rport, {$port}\n");
                        try {
                            if (!isset($this->connections[$port])) {
                                $this->connections[$port] = new MergerWorker($port, $loop_callback, $this->logger, $this->writers);
                            }
                            $this->connections[$port]->loop(new SequentialSocket(yield connect("tcp://$host:$rport")));
                            $this->logger->write("Connected to $host:$rport, {$port}\n");
                        } catch (\Exception $e) {
                            $this->logger->write("Exception {$e->getMessage()} in $host:$rport, {$port}\n");
                            $this->writers[key($this->writers)]->write(pack('VnC', 0, $port, Settings::ACTION_DISCONNECT));
                        }
                    });
                } else {
                    throw new \Exception("Got unknown cmd $cmd");
                }
            } else {
                if (!isset($this->connections[$port])) {
                    $this->connections[$port] = new MergerWorker($port, $loop_callback, $this->logger, $this->writers);
                }
                yield $this->connections[$port]->handleSharedRead($writerId, $buffer, $length);
            }

            if (fstat($buffer)['size'] > 1 * 1024 * 1024) {
                $rest = stream_get_contents($buffer);
                ftruncate($buffer, strlen($rest));
                fseek($buffer, 0);
                fwrite($buffer, $rest);
                fseek($buffer, 0);
            }
        }
    }
}
