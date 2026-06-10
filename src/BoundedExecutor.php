<?php

namespace Nexph\Runtime;

class BoundedExecutor
{
    private int $maxConcurrency;
    private int $running = 0;
    private array $queue = [];
    private int $queueHead = 0;

    public function __construct(int $maxConcurrency)
    {
        $this->maxConcurrency = $maxConcurrency;
    }

    public function submit(callable $task): void
    {
        if ($this->running < $this->maxConcurrency) {
            $this->run($task);
        } else {
            $this->queue[] = $task;
        }
    }

    private function run(callable $task): void
    {
        $this->running++;
        
        $fiber = new \Fiber(function() use ($task) {
            try {
                $task();
            } finally {
                $this->running--;
                $this->processQueue();
            }
        });
        
        $fiber->start();
    }

    private function processQueue(): void
    {
        if ($this->queueCount() > 0 && $this->running < $this->maxConcurrency) {
            $task = $this->dequeue();
            $this->run($task);
        }
    }
    
    private function queueCount(): int
    {
        return count($this->queue) - $this->queueHead;
    }
    
    private function dequeue(): mixed
    {
        $value = $this->queue[$this->queueHead++];
        
        if ($this->queueHead > 64 && $this->queueHead * 2 >= count($this->queue)) {
            $this->queue = array_slice($this->queue, $this->queueHead);
            $this->queueHead = 0;
        }
        
        return $value;
    }
    
    public function running(): int
    {
        return $this->running;
    }
    
    public function queued(): int
    {
        return $this->queueCount();
    }
}
