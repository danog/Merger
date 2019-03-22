<?php
namespace danog\Merger;

class Stats
{
    const MEAN_COUNT = 10;

    private static $instance;
    public static function getInstance($id = null)
    {
        if (!self::$instance) {
            self::$instance = new self;
        }
        if ($id === null) {
            return self::$instance;
        }
        return new class(self::$instance, $id) {
            /**
             * Stats instance
             *
             * @var Stats
             */
            private $instance;
            /**
             * ID
             *
             * @var an7
             */
            private $id;
            /**
             * Constructor
             *
             * @param [type] $instance
             * @param [type] $id
             */
            public function __construct($instance, $id)
            {
                $this->instance = $instance;
                $this->id = $id;
                $this->instance->allocate($id);
            }
            /**
             * Start sending data
             *
             * @return void
             */
            public function startSending()
            {
                $this->instance->startSending($this->id);
            }
            /**
             * Stop sending data
             *
             * @param int $sent Amount of data sent
             * 
             * @return void
             */
            public function stopSending($sent)
            {
                $this->instance->stopSending($this->id, $sent);
            }
        }
    }

    private $speeds = [];
    private $temp = [];

    public function allocate($ID)
    {
        $this->speeds[$ID] = new \Ds\Deque();
        $this->speeds[$ID]->allocate(self::MEAN_COUNT);
        $this->speeds[$ID]->push(array_fill(0, $this->speeds[$ID]->capacity(), 0));
    }
    public function startSending($ID)
    {
        $this->temp[$ID] = microtime(true);
    }
    public function stopSending($ID, $sent)
    {
        $this->speeds[$ID]->push(($sent * 8) / (microtime(true) - $this->temp[$ID]));
        $this->speeds[$ID]->pop();
        unset($this->temp[$ID]);
    }
    public function getSpeed($ID, $powerOf = 6)
    {
        return $this->speeds[$ID]->sum() / pow(10, $powerOf);
    }
    public function balance($bytes)
    {
        $sum = 0;
        $result = [];

        foreach ($this->speeds as $last_key => $elem) {
            $ret = $elem->sum(); 
            $sum += $ret;

            $result[$last_key] = $ret;
        }

        $per_bytes = (int) ($bytes / $sum);

        $sum = 0;

        foreach ($result as &$elem) {
            $elem *= $per_bytes;
            $sum += $elem;
        }

        $result[$last_key] -= $sum - $bytes;

        return $result;
    }
}
