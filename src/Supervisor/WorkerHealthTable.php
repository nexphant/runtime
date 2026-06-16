<?php

namespace nexphant\Runtime\Supervisor;

use nexphant\Runtime\SharedMemory\SharedMemoryInterface;
use nexphant\Runtime\SharedMemory\SysvSharedMemory;
use nexphant\Runtime\SharedMemory\FileSharedMemory;
use nexphant\Support\Extension\ExtensionDetector;

class WorkerHealthTable
{
    private SharedMemoryInterface $shm;
    private const TABLE_KEY = 'worker_health';

    public function __construct(?SharedMemoryInterface $shm = null)
    {
        $this->shm = $shm ?? $this->createSharedMemory();
    }

    private function createSharedMemory(): SharedMemoryInterface
    {
        if (ExtensionDetector::has('sysvshm')) {
            return new SysvSharedMemory(ftok(__FILE__, 'w'));
        }
        return new FileSharedMemory(sys_get_temp_dir() . '/nexphant_worker_health.dat');
    }

    public function update(int $workerId, array $status): void
    {
        $table = $this->getTable();
        $table[$workerId] = array_merge($status, ['updated_at' => time()]);
        $this->shm->write(self::TABLE_KEY, json_encode($table));
    }

    public function get(int $workerId): ?array
    {
        $table = $this->getTable();
        return $table[$workerId] ?? null;
    }

    public function getAll(): array
    {
        return $this->getTable();
    }

    public function remove(int $workerId): void
    {
        $table = $this->getTable();
        unset($table[$workerId]);
        $this->shm->write(self::TABLE_KEY, json_encode($table));
    }

    public function clear(): void
    {
        $this->shm->write(self::TABLE_KEY, json_encode([]));
    }

    private function getTable(): array
    {
        $data = $this->shm->read(self::TABLE_KEY);
        if ($data === null) {
            return [];
        }
        return json_decode($data, true) ?: [];
    }
}
