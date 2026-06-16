<?php

namespace Nexphant\Runtime\SharedMemory;

use Nexphant\Support\Extension\ExtensionDetector;

class WorkerHealthTable
{
    private SharedMemoryInterface $shm;
    private const MAX_WORKERS = 64;
    private const ENTRY_SIZE = 128;

    public function __construct(?SharedMemoryInterface $shm = null)
    {
        $this->shm = $shm ?? $this->createSharedMemory();
    }

    private function createSharedMemory(): SharedMemoryInterface
    {
        if (ExtensionDetector::has('sysvshm')) {
            return new SysvSharedMemory(ftok(__FILE__, 'w'), self::MAX_WORKERS * self::ENTRY_SIZE);
        }
        if (ExtensionDetector::has('shmop')) {
            return new ShmopSharedMemory(ftok(__FILE__, 'w'), self::MAX_WORKERS * self::ENTRY_SIZE);
        }
        return new FileSharedMemory('/tmp/nexphant-workers.shm', self::MAX_WORKERS * self::ENTRY_SIZE);
    }

    public function updateWorker(int $workerId, array $data): void
    {
        if ($workerId < 0 || $workerId >= self::MAX_WORKERS) {
            return;
        }

        $entry = [
            'worker_id' => $workerId,
            'pid' => $data['pid'] ?? 0,
            'status' => $data['status'] ?? 'idle',
            'requests' => $data['requests'] ?? 0,
            'errors' => $data['errors'] ?? 0,
            'last_heartbeat' => time(),
        ];

        $offset = $workerId * self::ENTRY_SIZE;
        $packed = json_encode($entry);
        $this->shm->write($offset, str_pad($packed, self::ENTRY_SIZE, "\0"));
    }

    public function getWorker(int $workerId): ?array
    {
        if ($workerId < 0 || $workerId >= self::MAX_WORKERS) {
            return null;
        }

        $offset = $workerId * self::ENTRY_SIZE;
        $data = rtrim($this->shm->read($offset, self::ENTRY_SIZE), "\0");
        
        if (empty($data)) {
            return null;
        }

        return json_decode($data, true);
    }

    public function getAllWorkers(): array
    {
        $workers = [];
        for ($i = 0; $i < self::MAX_WORKERS; $i++) {
            $worker = $this->getWorker($i);
            if ($worker !== null && $worker['pid'] > 0) {
                $workers[] = $worker;
            }
        }
        return $workers;
    }

    public function removeWorker(int $workerId): void
    {
        if ($workerId < 0 || $workerId >= self::MAX_WORKERS) {
            return;
        }

        $offset = $workerId * self::ENTRY_SIZE;
        $this->shm->write($offset, str_repeat("\0", self::ENTRY_SIZE));
    }

    public function cleanup(): void
    {
        $this->shm->cleanup();
    }
}
