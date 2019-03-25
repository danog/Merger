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

use Amp\Deferred;
use function Amp\asyncCall;
use function Amp\call;
use function Amp\Socket\connect;

abstract class SharedMerger
{
    protected $pending_in_payloads = [];
    protected $pending_out_payloads = [];
    protected $connection_out_seq_no = [];
    protected $connection_in_seq_no = [];

    const ACTION_CONNECT = 0;
    const ACTION_DISCONNECT = 1;
    public function readMore($socket, $buffer, $length)
    {
        return call([$this, 'readMoreAsync'], $socket, $buffer, $length);
    }
    public function readMoreAsync($socket, $buffer, $length)
    {
        $read = true;
        $pos = ftell($buffer);
        fseek($buffer, 0, SEEK_END);
        while (fstat($buffer)['size'] - $pos < $length && ($read = yield $socket->read()) !== null) {
            fwrite($buffer, $read);
        }
        fseek($buffer, $pos);
        return $read !== null;
    }
    public function commonWrite($port, $chunk)
    {
        $shared_deferred = new Deferred();
        $promise = $shared_deferred->promise();
        $length = fstat($chunk)['size'] - ftell($chunk);
        foreach ($this->shared_stats->balance($length) as $id => $bytes) {
            if ($bytes === 0) continue;
            $stats = $this->stats[$id];
            $seqno = $this->connection_out_seq_no[$port];
            $this->connection_out_seq_no[$port] = ($this->connection_out_seq_no[$port] + 1) % 0xFFFF;

            $this->logger->write("Still sending $port seqno $seqno         length $bytes\n");

            $this->writers[$id]->write(pack('Vnn', $bytes, $port, $seqno) . stream_get_contents($chunk, $bytes))->onResolve(
                function ($error = null, $result = null) use ($stats, &$shared_deferred, $bytes) {
                    if ($error) {
                        throw $error;
                    }
                    if ($shared_deferred) {
                        $shared_deferred->resolve();
                        $shared_deferred = null;
                    }
                    $stats->stopSending($result, $bytes);
                }
            );
        }
        ftruncate($chunk, 0);
        fseek($chunk, 0);
        return $promise;
    }

    public function handleSharedReads($id, $server)
    {
        $socket = $this->writers[$id];
        $buffer = $socket->getBuffer();

        while (true) {
            if (!yield $socket->read(6)) {
                $this->logger->write("Breaking out of $id\n");
                break;
            }

            $length = unpack('V', stream_get_contents($buffer, 4))[1];
            $port = unpack('n', stream_get_contents($buffer, 2))[1];

            $this->logger->write("Reading length $length port $port\n");
            if ($length === 0) {
                yield $socket->read(1);
                $cmd = ord(stream_get_contents($buffer, 1));
                $this->logger->write("Reading special action $cmd           $id\n");

                if ($cmd === self::ACTION_DISCONNECT) {
                    $this->connections[$port]->close();
                    unset($this->connections[$port]);
                    unset($this->connection_out_seq_no[$port]);
                    unset($this->connection_in_seq_no[$port]);
                    unset($this->pending_in_payloads[$port]);
                } else if ($cmd === self::ACTION_CONNECT && $server) {
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
                    $this->logger->write("Connecting to $host:$rport, $port\n");
                    try {
                        $this->connection_out_seq_no[$port] = 0;
                        $this->connection_in_seq_no[$port] = 0;
                        $this->pending_in_payloads[$port] = [];
                        $this->connections[$port] = yield connect("tcp://$host:$rport");
                        ksort($this->pending_in_payloads[$port]);
                        foreach ($this->pending_in_payloads[$port] as $seqno => $payload) {
                            if ($this->connection_in_seq_no[$port] !== $seqno) {
                                break;
                            }
                            $this->logger->write("Receiving proxy => $port seqno $seqno         init $id\n");

                            unset($this->pending_in_payloads[$port][$seqno]);
                            $this->connections[$port]->write($payload);
                            $this->connection_in_seq_no[$port] = ($this->connection_in_seq_no[$port] + 1) % 0xFFFF;
                        }
                        asyncCall([$this, 'handleClientReads'], $port);
                    } catch (\Exception $e) {
                        $this->writers[key($this->writers)]->write(pack('VnC', 0, $port, self::ACTION_DISCONNECT));
                    }
                } else if ($cmd > 1) {
                    throw new \Exception("Got unknown cmd $cmd");
                }
            } else {
                $this->logger->write("Reading payload\n");

                yield $socket->read($length + 2);
                $seqno = unpack('n', stream_get_contents($buffer, 2))[1];

                if (isset($this->connections[$port]) && $seqno === $this->connection_in_seq_no[$port]) {
                    $this->logger->write("Receiving proxy => $port seqno $seqno         main $id\n");
                    $this->connections[$port]->write($d = stream_get_contents($buffer, $length));
                    if (strlen($d) != $length) {
                        die('Wrong length');
                    }
                    $this->connection_in_seq_no[$port] = ($this->connection_in_seq_no[$port] + 1) % 0xFFFF;
                    ksort($this->pending_in_payloads[$port]);
                    foreach ($this->pending_in_payloads[$port] as $seqno => $payload) {
                        if ($this->connection_in_seq_no[$port] !== $seqno) {
                            break;
                        }
                        $this->logger->write("Receiving proxy => $port seqno $seqno         subloop $id\n");
                        unset($this->pending_in_payloads[$port][$seqno]);
                        $this->connections[$port]->write($payload);
                        $this->connection_in_seq_no[$port] = ($this->connection_in_seq_no[$port] + 1) % 0xFFFF;

                    }
                } else {
                    if (!isset($this->pending_in_payloads[$port])) {
                        $this->pending_in_payloads[$port] = [];
                    }
                    $this->logger->write("Postponing payload {$this->connection_in_seq_no[$port]} != seqno $seqno         postpone $id\n");

                    $this->pending_in_payloads[$port][$seqno] = stream_get_contents($buffer, $length);
                }
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
