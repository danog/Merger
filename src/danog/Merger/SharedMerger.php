<?php
namespace danog\Merger;

use function Amp\call;
use function Amp\Socket\connect;
use Amp\Deferred;
use function Amp\asyncCall;

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

            $this->logger->write("Still sending $port seqno $seqno\n");
            $stats->startSending();
            $this->writers[$id]->write(pack('Vnn', $bytes, $port, $seqno) . stream_get_contents($chunk, $bytes))->onResolve(
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
            $this->logger->write("Reading length\n");
            if (!yield $this->readMore($socket, $buffer, 6)) {
                $this->logger->write("Breaking out of $id\n");
                break;
            }
            
            $length = unpack('V', stream_get_contents($buffer, 4))[1];
            $port = unpack('n', stream_get_contents($buffer, 2))[1];

            if ($length === 0) {
                $this->logger->write("Reading special action           $id\n");

                yield $this->readMore($socket, $buffer, 1);
                $cmd = ord(stream_get_contents($buffer, 1));
                var_dump($cmd);
                if ($cmd === self::ACTION_DISCONNECT) {
                    $this->connections[$port]->close();
                    unset($this->connections[$port]);
                    unset($this->connection_out_seq_no[$port]);
                    unset($this->connection_in_seq_no[$port]);
                    unset($this->pending_in_payloads[$port]);
                } else if ($cmd === self::ACTION_CONNECT && $server) {
                    yield $this->readMore($socket, $buffer, 3);
                    $rport = unpack('n', stream_get_contents($buffer, 2))[1];
                    $type = ord(stream_get_contents($buffer, 1));
                    switch ($type) {
                        case 0x03:
                            yield $this->readMore($socket, $buffer, 1);
                            $toRead = ord(stream_get_contents($buffer, 1));
                            yield $this->readMore($socket, $buffer, $toRead);
                            $host = stream_get_contents($buffer, $toRead);
                            break;
                        case 0x04:
                            $toRead = 16;
                            yield $this->readMore($socket, $buffer, $toRead);
                            $host = '[' . inet_ntop(stream_get_contents($buffer, $toRead)) . ']';
                            break;
                        case 0x01:
                            $toRead = 4;
                            yield $this->readMore($socket, $buffer, $toRead);
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
                            //$this->logger->write($payload);

                            unset($this->pending_in_payloads[$port][$seqno]);
                            $this->connections[$port]->write($payload);
                            $this->connection_in_seq_no[$port]++;
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

                yield $this->readMore($socket, $buffer, $length + 2);
                $seqno = unpack('n', stream_get_contents($buffer, 2))[1];

                if (isset($this->connections[$port]) && $seqno === $this->connection_in_seq_no[$port]) {
                    $this->logger->write("Receiving proxy => $port seqno $seqno         main $id\n");
                    $this->connections[$port]->write($d = stream_get_contents($buffer, $length));
                    if (strlen($d) != $length) {
                        die('Wrong length');
                    }
                    $this->connection_in_seq_no[$port]++;
                    //$this->logger->write($data);
                    ksort($this->pending_in_payloads[$port]);
                    foreach ($this->pending_in_payloads[$port] as $seqno => $payload) {
                        if ($this->connection_in_seq_no[$port] !== $seqno) {
                            break;
                        }
                        $this->logger->write("Receiving proxy => $port seqno $seqno         subloop $id\n");
                        unset($this->pending_in_payloads[$port][$seqno]);
                        $this->connections[$port]->write($payload);
                        $this->connection_in_seq_no[$port]++;
                        //$this->logger->write($payload);

                    }
                } else {
                    if (!isset($this->pending_in_payloads[$port])) {
                        $this->pending_in_payloads[$port] = [];
                    }
                    $this->logger->write("Postponing payload {$this->connection_in_seq_no[$port]} != seqno $seqno         postpone $id\n");

                    $this->pending_in_payloads[$port][$seqno] = stream_get_contents($buffer, $length);
                }
            }

            if (fstat($buffer)['size'] > 10 * 1024 * 1024) {
                $this->logger->write("=============== Resetting buffer\n");

                $rest = stream_get_contents($buffer);
                fclose($buffer);
                $buffer = fopen('php://memory', 'r+');
                fwrite($buffer, $rest);
                fseek($buffer, 0);
            }
        }
    }
}
