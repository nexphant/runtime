<?php

namespace nexphant\Runtime\SharedMemory;

class SysvSharedMemory implements SharedMemoryInterface
{
    private $shm;
    private int $size;

    public function __construct(int $key, int $size = 65536)
    {
        if (!extension_loaded('sysvshm')) {
            throw new \RuntimeException('ext-sysvshm is not available');
        }

        $this->size = $size;
        $this->shm = shm_attach($key, $size, 0666);

        if ($this->shm === false) {
            throw new \RuntimeException('Failed to attach shared memory');
        }
    }

    public function read(int $offset, int $length): ?string
    {
        $data = @shm_get_var($this->shm, $offset);
        return $data !== false ? substr($data, 0, $length) : null;
    }

    public function write(int $offset, string $data): bool
    {
        return @shm_put_var($this->shm, $offset, $data);
    }

    public function readInt(int $offset): int
    {
        $data = @shm_get_var($this->shm, $offset);
        return $data !== false ? (int)$data : 0;
    }

    public function writeInt(int $offset, int $value): bool
    {
        return @shm_put_var($this->shm, $offset, $value);
    }

    public function increment(int $offset): int
    {
        $value = $this->readInt($offset) + 1;
        $this->writeInt($offset, $value);
        return $value;
    }

    public function decrement(int $offset): int
    {
        $value = $this->readInt($offset) - 1;
        $this->writeInt($offset, $value);
        return $value;
    }

    public function __destruct()
    {
        if ($this->shm) {
            @shm_detach($this->shm);
        }
    }
}
