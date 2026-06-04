<?php
namespace Nexph\Runtime;

use Nexph\Runtime\Backpressure\BoundedExecutor;

class Executor
{
    private BoundedExecutor $executor;

    public function __construct(int $maxConcurrent = 100)
    {
        $this->executor = new BoundedExecutor($maxConcurrent);
    }

    public function submit(callable $task): void
    {
        $this->executor->submit($task);
    }

    public function wait(): void
    {
        $this->executor->wait();
    }

    public static function create(int $maxConcurrent = 100): self
    {
        return new self($maxConcurrent);
    }
}
