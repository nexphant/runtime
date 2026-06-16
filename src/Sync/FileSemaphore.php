<?php

namespace nexphant\Runtime\Sync;

class FileSemaphore implements SemaphoreInterface
{
    private string $lockFile;
    private $handle;
    private bool $acquired = false;

    public function __construct(string $name)
    {
        $this->lockFile = sys_get_temp_dir() . '/nexphant-sem-' . md5($name) . '.lock';
    }

    public function acquire(float $timeout = 0): bool
    {
        if ($this->acquired) {
            return true;
        }

        $this->handle = fopen($this->lockFile, 'c');
        
        if ($this->handle === false) {
            return false;
        }

        $start = microtime(true);
        
        while (true) {
            if (flock($this->handle, LOCK_EX | LOCK_NB)) {
                $this->acquired = true;
                return true;
            }

            if ($timeout > 0 && (microtime(true) - $start) >= $timeout) {
                fclose($this->handle);
                $this->handle = null;
                return false;
            }

            usleep(1000);
        }
    }

    public function release(): bool
    {
        if (!$this->acquired || !$this->handle) {
            return false;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
        $this->acquired = false;

        return true;
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
