<?php

namespace nexphant\Runtime\Adaptive;

/**
 * Lightweight semaphore abstraction.
 * Uses ext-sysvsem when available, falls back to file-based lock.
 */
final class SemaphoreLock
{
    private $semaphore = null;
    private ?string $lockFile = null;
    private $lockFd = null;
    private bool $acquired = false;
    private bool $useSysv;

    public function __construct(int $key, ?string $fallbackPath = null)
    {
        $this->useSysv = extension_loaded('sysvsem');

        if ($this->useSysv) {
            $this->semaphore = @sem_get($key, 1, 0666, 1);
            if ($this->semaphore === false) {
                $this->semaphore = null;
                $this->useSysv = false;
            }
        }

        if (!$this->useSysv) {
            $this->lockFile = $fallbackPath ?? (sys_get_temp_dir() . '/nexphant-sem-' . $key . '.lock');
        }
    }

    public function acquire(): bool
    {
        if ($this->acquired) {
            return true;
        }

        if ($this->useSysv && $this->semaphore !== null) {
            if (@sem_acquire($this->semaphore, true)) {
                $this->acquired = true;
                return true;
            }
            return false;
        }

        // file fallback
        $this->lockFd = fopen($this->lockFile, 'c');
        if ($this->lockFd && flock($this->lockFd, LOCK_EX | LOCK_NB)) {
            $this->acquired = true;
            return true;
        }
        if ($this->lockFd) {
            fclose($this->lockFd);
            $this->lockFd = null;
        }
        return false;
    }

    public function release(): void
    {
        if (!$this->acquired) {
            return;
        }

        $this->acquired = false;

        if ($this->useSysv && $this->semaphore !== null) {
            @sem_release($this->semaphore);
            return;
        }

        if ($this->lockFd) {
            flock($this->lockFd, LOCK_UN);
            fclose($this->lockFd);
            $this->lockFd = null;
        }
    }

    /**
     * Run callable inside lock. Short critical sections only.
     */
    public function locked(callable $fn): mixed
    {
        if (!$this->acquire()) {
            return null;
        }
        try {
            return $fn();
        } finally {
            $this->release();
        }
    }

    public function isAvailable(): bool
    {
        return $this->useSysv
            ? $this->semaphore !== null
            : $this->lockFile !== null;
    }

    public function __destruct()
    {
        if ($this->acquired) {
            $this->release();
        }
    }
}
