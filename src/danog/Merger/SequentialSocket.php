<?php
namespace danog\Merger;

use Amp\Success;
use function Amp\call;


class SequentialSocket
{
    private $socket;
    private $buffer;
    private $last_write;
    private $stats;
    public function __construct($socket, $id = null)
    {
        $this->socket = $socket;
        $this->buffer = fopen('php://memory', 'r+');
        $this->last_write = new Success();
        $this->stats = Stats::getInstance($id);
    }
    public function setId($id)
    {
        $this->stats = Stats::getInstance($id);
    }
    public function getBuffer()
    {
        return $this->buffer;
    }
    public function read($length = 0)
    {
        return call([$this, 'readAsync'], $length);
    }
    public function readAsync($length)
    {
        if (!$length) {
            $pos = ftell($this->buffer);
            $read = yield $this->socket->read();
            fwrite($this->buffer, $read);
            fseek($this->buffer, $pos);
            return $read !== null;
        }
        $read = true;
        $pos = ftell($this->buffer);
        fseek($this->buffer, 0, SEEK_END);
        while (fstat($this->buffer)['size'] - $pos < $length && ($read = yield $this->socket->read()) !== null) {
            fwrite($this->buffer, $read);
        }
        fseek($this->buffer, $pos);
        return $read !== null;
    }
    public function write($data)
    {
        return $this->socket->write($data);
    }
    public function writeSequential($data)
    {
        return call([$this, 'writeAsync'], $data);
    }
    public function writeAsync($data)
    {
        yield $this->last_write;
        $started = microtime(true);
        $wrote = yield $this->last_write = $this->socket->write($data);
        $this->stats->stopSending($started, $wrote);
        return $wrote;
    }
    public function close()
    {
        return $this->socket->close();
    }
}