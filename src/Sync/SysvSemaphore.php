<?php

namespace Nexph\Runtime\Sync;

class SysvSemaphore implements SemaphoreInterface
{
    private $semaphore;
    private bool $acquired = false;

    public function __construct(int $key, int $maxAcquire = 1)
    {
        if (!extension_loaded('sysvsem')) {
            throw new \RuntimeException('ext-sysvsem is not available');
        }

        $this->semaphore = sem_get($key, $maxAcquire, 0666, 1);
        
        if ($this->semaphore === false) {
            throw new \RuntimeException('Failed to create semaphore');
        }
    }

    public function acquire(float $timeout = 0): bool
    {
        if ($this->acquired) {
            return true;
        }

        $start = microtime(true);
        
        while (true) {
            if (@sem_acquire($this->semaphore, true)) {
                $this->acquired = true;
                return true;
            }

            if ($timeout > 0 && (microtime(true) - $start) >= $timeout) {
                return false;
            }

            usleep(1000);
        }
    }

    public function release(): bool
    {
        if (!$this->acquired) {
            return false;
        }

        if (@sem_release($this->semaphore)) {
            $this->acquired = false;
            return true;
        }

        return false;
    }

    public function isAcquired(): bool
    {
        return $this->acquired;
    }

    public function __destruct()
    {
        if ($this->acquired) {
            $this->release();
        }
    }
}
