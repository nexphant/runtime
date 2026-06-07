<?php

namespace Nexph\Runtime\IPC;

class WorkerSharedMetrics
{
    private $shm;
    private $sem;
    private int $maxWorkers;
    private int $recordSize = 48;

    public function __construct(int $key, int $maxWorkers = 64)
    {
        if (!extension_loaded('shmop')) {
            throw new \RuntimeException('ext-shmop is not available');
        }
        if (!extension_loaded('sysvsem')) {
            throw new \RuntimeException('ext-sysvsem is not available');
        }

        $this->maxWorkers = $maxWorkers;
        $size = $this->recordSize * $maxWorkers;

        $this->shm = @shmop_open($key, 'c', 0644, $size);
        if ($this->shm === false) {
            throw new \RuntimeException('Failed to create shared memory');
        }

        $this->sem = @sem_get($key, 1, 0644, 1);
        if ($this->sem === false) {
            throw new \RuntimeException('Failed to create semaphore');
        }
    }

    public function update(int $workerId, array $data): void
    {
        if ($workerId < 1 || $workerId > $this->maxWorkers) {
            return;
        }

        $offset = ($workerId - 1) * $this->recordSize;

        sem_acquire($this->sem);
        try {
            $packed = pack('P6', 
                $data['request_count'] ?? 0,
                $data['active_connections'] ?? 0,
                $data['active_requests'] ?? 0,
                (int)(($data['last_seen'] ?? microtime(true)) * 1000000),
                (int)(($data['loop_tick_ms'] ?? 0.0) * 1000),
                0
            );
            shmop_write($this->shm, $packed, $offset);
        } finally {
            sem_release($this->sem);
        }
    }

    public function get(int $workerId): ?array
    {
        if ($workerId < 1 || $workerId > $this->maxWorkers) {
            return null;
        }

        $offset = ($workerId - 1) * $this->recordSize;

        sem_acquire($this->sem);
        try {
            $data = shmop_read($this->shm, $offset, $this->recordSize);
            $unpacked = unpack('P6', $data);
            
            return [
                'worker_id' => $workerId,
                'request_count' => $unpacked[1],
                'active_connections' => $unpacked[2],
                'active_requests' => $unpacked[3],
                'last_seen' => $unpacked[4] / 1000000.0,
                'loop_tick_ms' => $unpacked[5] / 1000.0,
            ];
        } finally {
            sem_release($this->sem);
        }
    }

    public function getAll(): array
    {
        $result = [];
        for ($i = 1; $i <= $this->maxWorkers; $i++) {
            $metrics = $this->get($i);
            if ($metrics && $metrics['last_seen'] > 0) {
                $result[] = $metrics;
            }
        }
        return $result;
    }
}
