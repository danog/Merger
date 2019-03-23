<?php
namespace danog\Merger;

use function Amp\asyncCall;
use function Amp\Socket\connect;
use function Amp\Socket\listen;
use Amp\Loop;
use Amp\ByteStream\ResourceOutputStream;

class MergerServer extends SharedMerger
{
    protected $settings;
    protected $writers = [];
    protected $connections = [];
    protected $stats = [];

    const STATE_HEADER = 0;
    const STATE_HEADER_CMD = 1;
    const STATE_DATA = 2;

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
        $server = listen($this->settings->getTunnelEndpoint());

        while ($socket = yield $server->accept()) {
            list($address, $port) = explode(':', stream_socket_get_name($socket->getResource(), true));
            $this->writers[$address . '-' . $port] = $socket;
            $this->stats[$address . '-' . $port] = Stats::getInstance($address . '-' . $port);
            $this->pending_out_payloads[$address . '-' . $port] = new \SplQueue;

            asyncCall([$this, 'handleSharedReads'], $address . '-' . $port, true);
        };
    }

    public function handleClientReads($port)
    {
        $this->logger->write("New $port\n");
        $socket = $this->connections[$port];

        $buffer = fopen('php://memory', 'r+');
        while (null !== $chunk = yield $socket->read()) {
            $this->logger->write("Sending $port => proxy\n");

            $pos = ftell($buffer);
            fwrite($buffer, $chunk);
            fseek($buffer, $pos);

            yield $this->commonWrite($port, $buffer);

            if (fstat($buffer)['size'] > 10 * 1024 * 1024) {
                $buffer = fopen('php://memory', 'r+');
            }
        }
        $this->logger->write("Closing $port\n");
        $this->writers[key($this->writers)]->write(pack('VnC', 0, $port, self::ACTION_DISCONNECT));
    }

}
