<?php
namespace nexphant\Runtime;

class FiberManager
{
    private array $fibers = [];

    public function create(callable $fn): \Fiber
    {
        $fiber = new \Fiber($fn);
        $this->fibers[] = $fiber;
        return $fiber;
    }

    public function cleanup(): void
    {
        $this->fibers = [];
    }
}
