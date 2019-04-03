<?php
namespace danog\Merger;

use Amp\Deferred;
use function Amp\asyncCall;
use function Amp\call;

class MergerWorker
{
    /**
     * Shared writers
     *
     * @var array
     */
    private $_writers;
    /**
     * Shared stats instance
     *
     * @var [type]
     */
    private $_sharedStats;
    /**
     * Main socket
     *
     * @var [type]
     */
    private $_socket;
    /**
     * Connection ID
     *
     * @var [type]
     */
    private $_port;
    /**
     * Logger instance
     *
     * @var [type]
     */
    private $_logger;

    private $_connectionOutSeqNo = 0;

    private $_connectionInSeqNo = 0;

    private $_pause;

    /**
     * Construct
     *
     * @param [type] $port
     * @param [type] $callback
     * @param [type] $logger
     * @param [type] $writers
     */
    public function __construct($port, $callback, $logger, &$writers)
    {
        $this->_port = $port;
        $this->_writers = $writers;
        $this->_logger = $logger;
        $this->_callback = $callback->bindTo($this, get_class($this));
        $this->_sharedStats = Stats::getInstance();
    }
    public function loop($socket)
    {
        $this->_socket = $socket;
        if ($this->_pause) {
            $pause = $this->_pause;
            $this->_pause = null;
            $pause->resolve();
        }
        asyncCall($this->_callback);
    }
    public function handleSharedReadAsync($writerId, $buffer, $length)
    {
        $socket = $this->_writers[$writerId];

        yield $socket->read($length + 2);
        $seqno = unpack('n', stream_get_contents($buffer, 2))[1];
        $data = stream_get_contents($buffer, $length);
        if ($this->_socket && $seqno === $this->_connectionInSeqNo) {
            $this->_socket->write($data);
            $this->_connectionInSeqNo = ($this->_connectionInSeqNo + 1) % 0xFFFF;
            if ($this->_pause) {
                $pause = $this->_pause;
                $this->_pause = null;
                $pause->resolve();
            }
        } else {
            $this->pauseDefer($seqno, $data);
        }
    }
    public function pauseDefer(...$args)
    {
        return asyncCall([$this, 'pauseDeferAsync'], ...$args);
    }
    public function pauseDeferAsync($seqno, $data)
    {
        while (!$this->_socket || $seqno !== $this->_connectionInSeqNo) {
            if (!$this->_pause) {
                $this->_pause = new Deferred;
            }
            yield $this->_pause->promise();
        }
        $this->_socket->write($data);
        $this->_connectionInSeqNo = ($this->_connectionInSeqNo + 1) % 0xFFFF;
        if ($this->_pause) {
            $pause = $this->_pause;
            $this->_pause = null;
            $pause->resolve();
        }
    }

    public function commonWrite($chunk)
    {
        try {
            $shared_deferred = new Deferred();
            $promise = $shared_deferred->promise();
            $length = fstat($chunk)['size'] - ftell($chunk);
            foreach ($this->_sharedStats->balance($length) as $writerId => $bytes) {
                if ($bytes <= 0) {
                    $this->_logger->write("Skipping $bytes\n");
                    continue;
                }

                $seqno = $this->_connectionOutSeqNo;
                $this->_connectionOutSeqNo = ($this->_connectionOutSeqNo + 1) % 0xFFFF;

                $this->_writers[$writerId]->writeSequential(pack('Vnn', $bytes, $this->_port, $seqno) . stream_get_contents($chunk, $bytes))->onResolve(
                    function ($error = null, $result = null) use (&$shared_deferred) {
                        if ($error) {
                            throw $error;
                        }
                        if ($shared_deferred) {
                            $shared_deferred->resolve();
                            $shared_deferred = null;
                        }
                    }
                );
            }
            fseek($chunk, 0);
            ftruncate($chunk, 0);
            return $promise;
        } catch (\Exception $e) {
            return $this->_logger->write($e);
        }
    }
    public function close()
    {
        if (!$this->_socket) {
            return;
        }
        $socket = $this->_socket;
        $this->_socket = null;
        $this->_logger->write("Closing {$this->_port}\n");
        $socket->close();
        $this->_writers[0]->write(pack('VnC', 0, $this->_port, Settings::ACTION_DISCONNECT));
    }

    public function handleSharedRead($writerId, $buffer, $length)
    {
        return call([$this, 'handleSharedReadAsync'], $writerId, $buffer, $length);
    }
}
