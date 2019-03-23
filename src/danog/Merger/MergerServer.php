<?php
namespace danog\Merger;

use function Amp\asyncCall;
use function Amp\Socket\connect;
use function Amp\Socket\listen;
use Amp\Loop;

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
        /*
        Loop::repeat(1000, function () {
            var_dump($this->shared_stats->getSpeeds());
        });*/
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
            asyncCall([$this, 'handleSharedReads'], $address . '-' . $port, true);
        };
    }

    public function handleClientReads($port)
    {
        var_dump("New $port");
        $socket = $this->connections[$port];

        $buffer = fopen('php://memory', 'r+');
        while (null !== $chunk = yield $socket->read()) {
            var_dumP("Sending $port => proxy");

            $pos = ftell($buffer);
            fwrite($buffer, $chunk);
            fseek($buffer, $pos);

            yield $this->commonWrite($port, $buffer);

            if (fstat($buffer)['size'] > 10 * 1024 * 1024) {
                $buffer = fopen('php://memory', 'r+');
            }
        }
        var_dump("Closing $port");
        $this->writers[key($this->writers)]->write(pack('VnC', 0, $port, self::ACTION_DISCONNECT));
    }

}
