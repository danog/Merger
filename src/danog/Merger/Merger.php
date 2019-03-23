<?php
namespace danog\Merger;

use Amp\Socket\ClientConnectContext;
use function Amp\asyncCall;
use function Amp\Socket\connect;
use function Amp\Socket\listen;
use Amp\Loop;
use Amp\ByteStream\ResourceOutputStream;

class Merger extends SharedMerger
{
    protected $settings;
    protected $writers = [];
    protected $connections = [];
    protected $stats = [];
    protected $shared_stats = [];
    protected $connection_seqno = 0;

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
        $this->logger = new ResourceOutputStream(fopen('php://stdout', 'r+'));
        
        Loop::repeat(1000, function () {
            $this->logger->write(json_encode($this->shared_stats->getSpeeds(), JSON_PRETTY_PRINT));
        });
    }
    public function loop()
    {
        Loop::run([$this, 'loopAsync']);
    }
    public function loopAsync()
    {
        foreach ($this->settings->getConnectFromAddresses() as $bindto) {
            for ($x = 0; $x < $this->settings->getConnectionCount(); $x++) {
                $context = (new ClientConnectContext())->withBindTo($bindto);
                $this->writers[$bindto . '-' . $x] = yield connect('tcp://' . $this->settings->getTunnelEndpoint(), $context);
                $this->stats[$bindto . '-' . $x] = Stats::getInstance($bindto . '-' . $x);
                $this->pending_out_payloads[$bindto . '-' . $x] = new \SplQueue;
                asyncCall([$this, 'handleSharedReads'], $bindto . '-' . $x, false);
            }
        }

        $server = listen("127.0.0.1:55555");

        while ($socket = yield $server->accept()) {
            $port = explode(':', stream_socket_get_name($socket->getResource(), true))[1];
            $port = $this->connection_seqno++;
            $this->connections[$port] = $socket;
            $this->connection_out_seq_no[$port] = 0;
            $this->connection_in_seq_no[$port] = 0;
            $this->pending_in_payloads[$port] = [];
            asyncCall([$this, 'handleClientReads'], $port);
        };
    }
    public function handleClientReads($port)
    {
        var_dumP("New $port\n");
        $socket = $this->connections[$port];

        $socksInit = fopen('php://memory', 'r+');
        yield $this->readMore($socket, $socksInit, 2);

        if (fread($socksInit, 1) !== chr(5)) {
            throw new \Exception('Wrong socks5 init ');
        }
        yield $socket->write(chr(5));
        $auth = null;
        for ($x = 0; $x < ord(fread($socksInit, 1)); $x++) {
            yield $this->readMore($socket, $socksInit, 1);
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

        yield $this->readMore($socket, $socksInit, 4);
        if (fread($socksInit, 3) !== chr(5) . chr(1) . $authchr) {
            throw new \Exception('Wrong socks5 ack');
        }
        if ($auth) {
            yield $this->readMore($socket, $socksInit, 2);
            $ulen = ord(fread(2)[1]);
            yield $this->readMore($socket, $socksInit, $ulen);
            $username = fread($socksInit, $ulen);

            $plen = ord(fread(1));
            yield $this->readMore($socket, $socksInit, $plen);
            $password = fread($socksInit, $plen);

            var_dumP($username, $password);

        }
        $payload = fread($socksInit, 1);
        switch (ord($payload[0])) {
            case 0x03:
                yield $this->readMore($socket, $socksInit, 1);
                $payload .= fread($socksInit, 1);
                $toRead = ord($payload[1]);
                yield $this->readMore($socket, $socksInit, $toRead);
                $payload .= fread($socksInit, $toRead);
                break;
            case 0x04:
                $toRead = 16;
                yield $this->readMore($socket, $socksInit, $toRead);
                $payload .= fread($socksInit, $toRead);
                break;
            case 0x01:
                $toRead = 4;
                yield $this->readMore($socket, $socksInit, $toRead);
                $payload .= fread($socksInit, $toRead);
                break;
        }
        yield $this->readMore($socket, $socksInit, 2);
        $rport = unpack('n', fread($socksInit, 2))[1];

        yield $socket->write(chr(5) . chr(0) . chr(0) . chr(1) . pack('Vn', 0, 0));

        var_Dump("================================ SENDING CONNECT ================================");
        yield $this->writers[key($this->writers)]->write(pack('VnCn', 0, $port, self::ACTION_CONNECT, $rport) . $payload);

        $buffer = $socksInit;
        if (fstat($buffer)['size'] - ftell($buffer)) {
            yield $this->commonWrite($port, $buffer);
        }
        while (null !== $chunk = yield $socket->read()) {
            //var_dumP("Sending $port => proxy\n");
            $pos = ftell($buffer);
            fwrite($buffer, $chunk);
            fseek($buffer, $pos);
            yield $this->commonWrite($port, $buffer);

            if (fstat($buffer)['size'] > 10 * 1024 * 1024) {
                $buffer = fopen('php://memory', 'r+');
            }
        }
        yield $this->writers[key($this->writers)]->write(pack('VnC', 0, $port, self::ACTION_DISCONNECT));
    }

}
