<?php

namespace Nexphant\Runtime\SharedMemory;

interface SharedMemoryInterface
{
    public function read(int $offset, int $length): ?string;
    public function write(int $offset, string $data): bool;
    public function readInt(int $offset): int;
    public function writeInt(int $offset, int $value): bool;
    public function increment(int $offset): int;
    public function decrement(int $offset): int;
}
