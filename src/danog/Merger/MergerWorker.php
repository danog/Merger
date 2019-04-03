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
    private $_pendingPayloads = [];

    private $_connectionInSubSeqNo = [];
    private $_pendingSubPayloads = [];

    private $_pause;
    private $_minPauseSeqno = 0;

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
        $this->_pause = new Deferred;
        $this->_connectionInSubSeqNo = array_fill_keys(array_keys($this->_writers), 0);
    }
    public function loop($socket)
    {
        $this->_socket = $socket;
        $this->parsePending();
        asyncCall($this->_callback);
    }
    public function handleSharedReadAsync($writerId, $buffer, $length)
    {
        $socket = $this->_writers[$writerId];

        yield $socket->read($length + 2);
        $seqno = unpack('n', stream_get_contents($buffer, 2))[1];

        if ($this->_socket && $seqno === $this->_connectionInSeqNo && !$this->_connectionInSubSeqNo[$writerId]) {
            $this->_logger->write("Receiving payload with seqno $seqno                    main $writerId\n");
            $this->_socket->write(stream_get_contents($buffer, $length));
            $this->_connectionInSeqNo = ($this->_connectionInSeqNo + 1) % 0xFFFF;

            if ($this->_connectionInSeqNo === 0) {
                foreach ($this->_connectionInSubSeqNo as &$sseqno) {
                    $sseqno--;
                }
                $this->_pendingPayloads = $this->_pendingSubPayloads ? array_shift($this->_pendingSubPayloads) : [];
                //ksort($this->_pendingPayloads);
                /*
            $this->_pause->resolve();
            $this->_pause = new Deferred;
            $this->_minPauseSeqno = 0;
             */
            }
            $this->parsePending();
        } else {
            if (!$this->_connectionInSubSeqNo[$writerId]) {
                $this->_logger->write("Postponing payload with seqno $seqno (curseq {$this->_connectionInSeqNo})        postpone $writerId\n");
                $this->_pendingPayloads[$seqno] = stream_get_contents($buffer, $length);
                //ksort($this->_pendingPayloads);
                /*
            if ($seqno - $this->_connectionInSeqNo > 200) {
            $this->_logger->write("Pausing {$this->_port} - $writerId\n");
            $this->_minPauseSeqno = $this->_minPauseSeqno ? min($this->_minPauseSeqno, $this->_connectionInSeqNo) : $this->_connectionInSeqNo;
            yield $this->_pause->promise();
            $this->_logger->write("Resuming {$this->_port} - $writerId\n");
            }*/
            } else {
                $this->_logger->write("Postponing payload with seqno $seqno (curseq {$this->_connectionInSeqNo}) - {$this->_connectionInSubSeqNo[$writerId]}     postpone $writerId\n");
                $this->_pendingSubPayloads[$this->_connectionInSubSeqNo[$writerId]][$seqno] = stream_get_contents($buffer, $length);
                /*
            if ($this->_connectionInSubSeqNo[$writerId] > 1 || (0xFFFF + $seqno) - $this->_connectionInSeqNo > 200) {
            $this->_logger->write("Pausing {$this->_port} - $writerId\n");
            $this->_minPauseSeqno = $this->_minPauseSeqno ? min($this->_minPauseSeqno, $this->_connectionInSeqNo) : $this->_connectionInSeqNo;
            yield $this->_pause->promise();
            $this->_logger->write("Resuming {$this->_port} - $writerId\n");
            }*/
            }
        }

    }
    public function sync($writerId)
    {
        $seqno = ++$this->_connectionInSubSeqNo[$writerId];
        if (!isset($this->_pendingSubPayloads[$seqno])) {
            $this->_pendingSubPayloads[$seqno] = [];
        }
    }

    public function commonWrite($chunk)
    {
        $shared_deferred = new Deferred();
        $promise = $shared_deferred->promise();
        $length = fstat($chunk)['size'] - ftell($chunk);
        foreach ($this->_sharedStats->balance($length) as $writerId => $bytes) {
            if ($bytes <= 0) {
                continue;
            }

            $seqno = $this->_connectionOutSeqNo;
            $this->_connectionOutSeqNo = ($this->_connectionOutSeqNo + 1) % 0xFFFF;
            if ($this->_connectionOutSeqNo === 0) {
                foreach ($this->_writers as $writer) {
                    $writer->write(pack('VnC', 0, $this->_port, Settings::ACTION_SYNC));
                }
            }
            $this->_logger->write("Still sending {$this->_port} seqno $seqno         length $bytes\n");

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
    }

    public function parsePending()
    {
        for ($seqno = $this->_connectionInSeqNo; $seqno < 0xFFFF && isset($this->_pendingPayloads[$seqno]); $seqno++) {
            $payload = $this->_pendingPayloads[$seqno];
            $this->_logger->write("Receiving proxy => {$this->_port} seqno $seqno                      post\n");

            unset($this->_pendingPayloads[$seqno]);
            $this->_socket->write($payload);
            $this->_connectionInSeqNo = ($this->_connectionInSeqNo + 1) % 0xFFFF;
            if ($this->_connectionInSeqNo === 0) {
                foreach ($this->_connectionInSubSeqNo as &$sseqno) {
                    $sseqno--;
                }
                $this->_pendingSubPayloads ? array_shift($this->_pendingSubPayloads) : [];
                //ksort($this->_pendingPayloads);
                /*
                $this->_pause->resolve();
                $this->_pause = new Deferred;
                $this->_minPauseSeqno = 0;
                 */
                $this->parsePending();
            }
        }
        /*
    if ($this->_minPauseSeqno && $this->_connectionInSeqNo > $this->_minPauseSeqno) {
    $this->_pause->resolve();
    $this->_pause = new Deferred;
    $this->_minPauseSeqno = 0;
    }*/
    }
    public function close()
    {
        if (!$this->_socket) {
            return;
        }
        return $this->_socket->close();
    }

    public function handleSharedRead($writerId, $buffer, $length)
    {
        return call([$this, 'handleSharedReadAsync'], $writerId, $buffer, $length);
    }
}
