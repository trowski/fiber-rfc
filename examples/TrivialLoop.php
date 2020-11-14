<?php

declare(strict_types = 1);


class TrivialLoop implements FiberScheduler
{
    private $things_to_run = [];

    /**
     * @var Continuation[]
     */
    private $mah_continuations = [];

    public function add(callable $fn): void
    {
        $this->things_to_run[] = $fn;
    }

    public function go(): void
    {
        echo "go\n";
        foreach ($this->things_to_run as $thing_to_run) {
            $setup = function (Continuation $continuation) use ($thing_to_run): void {
                // Fiber::run($thing_to_run);
                echo "well, we reached here.\n";
                $this->mah_continuations[] = $continuation;
            };

//            $value = Fiber::suspend($thing_to_run, $this);
            $value = Fiber::suspend($setup, $this);
            var_dump($value);
        }
        echo "end go\n";
    }

    public function run(): void
    {
        echo "start run\n";
        foreach ($this->mah_continuations as $continuation) {
            //$continuation->isPending()
            $continuation->resume(5);
        }

        echo "end run\n";
    }
}