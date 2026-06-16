<?php

namespace nexphant\Runtime\IPC;

class AtomicCounter
{
    private $shm;
    private $sem;
    private int $offset;

    public function __construct(int $key, int $offset = 0)
    {
        if (!extension_loaded('shmop')) {
            throw new \RuntimeException('ext-shmop is not available');
        }
        if (!extension_loaded('sysvsem')) {
            throw new \RuntimeException('ext-sysvsem is not available');
        }

        $this->shm = @shmop_open($key, 'c', 0644, 1024);
        if ($this->shm === false) {
            throw new \RuntimeException('Failed to create shared memory');
        }

        $this->sem = @sem_get($key, 1, 0644, 1);
        if ($this->sem === false) {
            throw new \RuntimeException('Failed to create semaphore');
        }

        $this->offset = $offset;
    }

    public function increment(int $delta = 1): int
    {
        sem_acquire($this->sem);
        try {
            $data = shmop_read($this->shm, $this->offset, 8);
            $value = unpack('P', $data)[1] ?? 0;
            $value += $delta;
            shmop_write($this->shm, pack('P', $value), $this->offset);
            return $value;
        } finally {
            sem_release($this->sem);
        }
    }

    public function decrement(int $delta = 1): int
    {
        return $this->increment(-$delta);
    }

    public function get(): int
    {
        sem_acquire($this->sem);
        try {
            $data = shmop_read($this->shm, $this->offset, 8);
            return unpack('P', $data)[1] ?? 0;
        } finally {
            sem_release($this->sem);
        }
    }

    public function set(int $value): void
    {
        sem_acquire($this->sem);
        try {
            shmop_write($this->shm, pack('P', $value), $this->offset);
        } finally {
            sem_release($this->sem);
        }
    }
}
