<?php

namespace nexphant\Runtime\SharedMemory;

class FileSharedMemory implements SharedMemoryInterface
{
    private string $file;
    private $handle;

    public function __construct(string $name, int $size = 65536)
    {
        $this->file = sys_get_temp_dir() . '/nexphant-shm-' . md5($name) . '.dat';
        
        if (!file_exists($this->file)) {
            file_put_contents($this->file, str_repeat("\0", $size));
        }

        $this->handle = fopen($this->file, 'r+b');
        
        if ($this->handle === false) {
            throw new \RuntimeException('Failed to open shared memory file');
        }
    }

    public function read(int $offset, int $length): ?string
    {
        fseek($this->handle, $offset);
        $data = fread($this->handle, $length);
        return $data !== false ? $data : null;
    }

    public function write(int $offset, string $data): bool
    {
        fseek($this->handle, $offset);
        return fwrite($this->handle, $data) !== false;
    }

    public function readInt(int $offset): int
    {
        $data = $this->read($offset, 8);
        return $data ? (int)unpack('q', $data)[1] : 0;
    }

    public function writeInt(int $offset, int $value): bool
    {
        return $this->write($offset, pack('q', $value));
    }

    public function increment(int $offset): int
    {
        flock($this->handle, LOCK_EX);
        $value = $this->readInt($offset) + 1;
        $this->writeInt($offset, $value);
        flock($this->handle, LOCK_UN);
        return $value;
    }

    public function decrement(int $offset): int
    {
        flock($this->handle, LOCK_EX);
        $value = $this->readInt($offset) - 1;
        $this->writeInt($offset, $value);
        flock($this->handle, LOCK_UN);
        return $value;
    }

    public function __destruct()
    {
        if ($this->handle) {
            fclose($this->handle);
        }
    }
}
