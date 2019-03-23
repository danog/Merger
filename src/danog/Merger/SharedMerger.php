<?php
namespace danog\Merger;

use function Amp\call;
use function Amp\Socket\connect;
use Amp\Deferred;
use function Amp\asyncCall;

abstract class SharedMerger
{
    protected $pending_payloads = [];
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
        while (fstat($buffer)['size'] - ($pos = ftell($buffer)) < $length && ($read = yield $socket->read()) !== null) {
            fwrite($buffer, $read);
            fseek($buffer, $pos);
        }
        return $read !== null;
    }
    public function commonWrite($port, $chunk)
    {
        $deferred = new Deferred();
        $promise = $deferred->promise();
        $length = fstat($chunk)['size'] - ftell($chunk);
        foreach ($this->shared_stats->balance($length) as $id => $bytes) {
            $stats = $this->stats[$id];
            $seqno = $this->connection_out_seq_no[$port];
            $this->connection_out_seq_no[$port] = ($this->connection_out_seq_no[$port]+1) % 0xFFFF;

            var_dump("Still sending $port seqno $seqno");
            $stats->startSending();
            $this->writers[$id]->write(pack('Vnn', $bytes, $port, $seqno) . fread($chunk, $bytes))->onResolve(
                function ($error = null, $result = null) use ($stats, &$deferred, $port) {
                    if ($error) {
                        throw $error;
                    }
                    if ($deferred) {
                        $deferred->resolve(true);
                        $deferred = null;
                    }
                    $stats->stopSending($result);
                }
            );
        }
        return $promise;
    }

    public function handleSharedReads($id, $server)
    {
        $socket = $this->writers[$id];

        $buffer = fopen('php://memory', 'r+');

        while (true) {
            if (!yield $this->readMore($socket, $buffer, 6)) {
                break;
            }
            $length = unpack('V', fread($buffer, 4))[1];
            $port = unpack('n', fread($buffer, 2))[1];

            if ($length === 0) {
                yield $this->readMore($socket, $buffer, 1);
                $cmd = ord(fread($buffer, 1));
                if ($cmd === self::ACTION_DISCONNECT) {
                    $this->connections[$port]->close();
                    unset($this->connections[$port]);
                    unset($this->connection_out_seq_no[$port]);
                    unset($this->connection_in_seq_no[$port]);
                    unset($this->pending_payloads[$port]);
                } else if ($cmd === self::ACTION_CONNECT && $server) {
                    yield $this->readMore($socket, $buffer, 3);
                    $rport = unpack('n', fread($buffer, 2))[1];
                    $type = ord(fread($buffer, 1));
                    switch ($type) {
                        case 0x03:
                            yield $this->readMore($socket, $buffer, 1);
                            $toRead = ord(fread($buffer, 1));
                            yield $this->readMore($socket, $buffer, $toRead);
                            $host = fread($buffer, $toRead);
                            break;
                        case 0x04:
                            $toRead = 16;
                            yield $this->readMore($socket, $buffer, $toRead);
                            $host = '[' . inet_ntop(fread($buffer, $toRead)) . ']';
                            break;
                        case 0x01:
                            $toRead = 4;
                            yield $this->readMore($socket, $buffer, $toRead);
                            $host = inet_ntop(fread($buffer, $toRead));
                            break;
                    }
                    var_dump("Connecting to $host:$rport, $port");
                    try {
                        $this->connection_out_seq_no[$port] = 0;
                        $this->connection_in_seq_no[$port] = 0;
                        $this->pending_payloads[$port] = [];
                        $this->connections[$port] = yield connect("tcp://$host:$rport");

                        foreach ($this->pending_payloads[$port] as $seqno => $payload) {
                            if ($this->connection_in_seq_no[$port] !== $seqno) {
                                break;
                            }
                            var_dump("Receiving proxy => $port seqno $seqno");
                            var_dumP($payload);

                            unset($this->pending_payloads[$port][$seqno]);
                            $this->connections[$port]->write($payload);
                            $this->connection_in_seq_no[$port]++;
                        }
                        asyncCall([$this, 'handleClientReads'], $port);
                    } catch (\Exception $e) {
                        $this->writers[key($this->writers)]->write(pack('VnC', 0, $port, self::ACTION_DISCONNECT));
                    }
                }
            } else {
                yield $this->readMore($socket, $buffer, $length + 2);
                $seqno = unpack('n', fread($buffer, 2))[1];

                if (isset($this->connections[$port]) && $seqno === $this->connection_in_seq_no[$port]) {
                    var_dump("Recieving proxy => $port seqno $seqno");
                    $this->connections[$port]->write($data = fread($buffer, $length));
                    $this->connection_in_seq_no[$port]++;
                    var_dumP($data);
                    foreach ($this->pending_payloads[$port] as $seqno => $payload) {
                        if ($this->connection_in_seq_no[$port] !== $seqno) {
                            break;
                        }
                        var_dump("Receiving proxy => $port seqno $seqno");
                        unset($this->pending_payloads[$port][$seqno]);
                        $this->connections[$port]->write($payload);
                        $this->connection_in_seq_no[$port]++;
                        var_dumP($payload);

                    }
                } else {
                    if (!isset($this->pending_payloads[$port])) {
                        $this->pending_payloads[$port] = [];
                    }

                    $this->pending_payloads[$port][$seqno] = fread($buffer, $length);
                }
            }

            if (fstat($buffer)['size'] > 10 * 1024 * 1024) {
                $rest = stream_get_contents($buffer);
                fclose($buffer);
                $buffer = fopen('php://memory', 'r+');
                fwrite($buffer, $rest);
                fseek($buffer, 0);
            }
        }
    }
}
