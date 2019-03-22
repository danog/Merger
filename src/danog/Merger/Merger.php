<?php
namespace danog\Merger;

use Amp\Socket\ClientConnectContext;
use function Amp\asyncCall;
use function Amp\coroutine;
use function Amp\Socket\connect;
use function Amp\Socket\listen;

class Merger
{
    private $settings;
    private $writers = [];
    private $connections = [];
    private $stats = [];

    const STATE_HEADER = 0;
    const STATE_DATA = 1;

    /**
     * Constructor
     *
     * @param \danog\Merger\Settings $settings
     */
    public function __construct($settings)
    {
        $this->settings = $settings;
        $this->shared_stats = Stats::getInstance();
    }
    public function loop()
    {
        Loop::run([$this, 'loopAsync']);
    }
    public function loopAsync()
    {
        foreach ($settings->getConnectFromAddresses() as $bindto) {
            for ($x = 0; $x < $settings->getConnectionCount(); $x++) {
                $context = (new ClientConnectContext())->withBindTo($bindto);
                $this->writers[$bindto . '-' . $x] = yield connect($settings->getTunnelEndpoint(), $context);
                $this->stats[$bindto . '-' . $x] = Stats::getInstance($bindto . '-' . $x);
                asyncCall([$this, 'handleSharedReads'], $bindto . '-' . $x);
            }
        }

        $server = listen("127.0.0.1:55555");

        while ($socket = yield $server->accept()) {
            socket_getsockname($socket->getResource(), $addr, $port);
            $this->connections[$port] = $socket;
            asyncCall([$this, 'handleClientReads'], $port);
        };
    }
    public function handleSharedReads($id)
    {
        $socket = $this->writers[$id];

        $buffer = fopen('php://memory', 'r+');
        $state = self::STATE_HEADER;
        while (true) {
            fwrite($buffer, yield $socket->read());

            if ($state === self::STATE_HEADER && fstat($buffer)['size'] - ftell($buffer) >= 6) {
                list($length, $port) = unpack('Vn', fread($buffer, 6));
                $state = self::STATE_DATA;
            }
            if ($state === self::STATE_DATA && ($left = fstat($buffer)['size'] - ftell($buffer))) {
                $toWrite = min($length, $left);
                $this->connections[$port]->write(fread($buffer, $toWrite));
                $length -= $toWrite;

                if (!$length) {
                    $state = self::STATE_HEADER;
                }
            }

            if (fstats($buffer)['size'] > 10 * 1024 * 1024) {
                $rest = stream_get_contents($buffer);
                $buffer = fopen('php://memory', 'r+');
                fwrite($buffer, $rest);
            }
        }
    }

    public function handleClientReads($port)
    {
        $socket = $this->connections[$port];

        $socksInit = $this->readMore($socket, 3);
        if (substr($socksInit, 0, 3) !== chr(5) . chr(1) . chr(0)) {
            throw new \Exception('Wrong socks5 init');
        }
        $socksInit = substr($socksInit, 3);

        yield $socket->write(chr(5) . chr(0));

        $socksInit = $this->readMore($socket, 4, $socksInit);
        if (substr($socksInit, 0, 3) !== chr(5) . chr(1) . chr(0)) {
            throw new \Exception('Wrong socks5 init');
        }
        $socksInit = substr($socksInit, 3);

        if (ord($socksInit[0]) === 0x03) {
            $socksInit = $this->readMore($socket, 1, substr($socksInit, 1));
            $toRead = ord($socksInit[0]);
            $socksInit = $this->readMore($socket, $toRead, substr($socksInit, 1));
            $host = substr($socksInit, 0, $toRead);
        } else {
            $toRead = ord($socksInit[0]) === 0x01 ? 4 : 16;
            $socksInit = $this->readMore($socket, $toRead, substr($socksInit, 1));
            $host = inet_ntop(substr($socksInit, 0, $toRead));
        }
        $socksInit = $this->readMore($socket, 2, substr($socksInit, $toRead));
        $port = unpack('n', substr($socksInit, 0, 2))[1];
        $socksInit = substr($socksInit, 2);

        yield $socket->write(chr(5) . chr(0) . chr(0) . chr(1) . pack('Vn', 0, 0));

        $this->writers[0]->write(pack('VCnV', 0, 0, $port, strlen($host)) . $host);

        $buffer = fopen('php://memory', 'r+');
        while (null !== $chunk = yield $socket->read()) {
            fwrite($buffer, $chunk);
            yield coroutine($this->commonWrite($port, $buffer));

            if (fstats($buffer)['size'] > 10 * 1024 * 1024) {
                $buffer = fopen('php://memory', 'r+');
            }
        }
        $this->writers[0]->write(pack('VC', 0, 1));
    }

    public function readMore($socket, $length, $orig = '')
    {
        while (strlen($orig) < $length) {
            $orig .= yield $socket->read();
        }
        return $orig;
    }
    public function commonWrite($port, $chunk): \Generator
    {
        $length = fstat($chunk)['size'] - ftell($chunk);
        foreach ($this->shared_stats->balance($length) as $id => $bytes) {
            $stats = $this->stats[$id];

            $stats->startSending();
            $this->writers[$id]->write(pack('Vn', $bytes, $port) . fread($chunk, $bytes))->onResolve(
                function (Throwable $error = null, $result = null) use ($stats) {
                    if ($error) {
                        throw $error;
                    }
                    $stats->stopSending($result);
                }
            );
        }
    }
}
