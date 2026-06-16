<?php

namespace nexphant\Runtime\SharedMemory;

class ShmopMemory implements SharedMemoryInterface
{
    private $shm;
    private int $size;

    public function __construct(int $key, int $size = 65536)
    {
        if (!extension_loaded('shmop')) {
            throw new \RuntimeException('ext-shmop is not available');
        }

        $this->size = $size;
        $this->shm = @shmop_open($key, 'c', 0666, $size);

        if ($this->shm === false) {
            throw new \RuntimeException('Failed to open shared memory');
        }
    }

    public function read(int $offset, int $length): ?string
    {
        $data = @shmop_read($this->shm, $offset, $length);
        return $data !== false ? $data : null;
    }

    public function write(int $offset, string $data): bool
    {
        return @shmop_write($this->shm, $data, $offset) !== false;
    }

    public function readInt(int $offset): int
    {
        $data = @shmop_read($this->shm, $offset, 8);
        return $data !== false ? (int)unpack('q', $data)[1] : 0;
    }

    public function writeInt(int $offset, int $value): bool
    {
        return $this->write($offset, pack('q', $value));
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
            @shmop_close($this->shm);
        }
    }
}
