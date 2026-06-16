<?php

namespace Nexphant\Runtime\Adaptive;

/**
 * Cross-worker shared load table using shmop (preferred) or sysvshm.
 *
 * Binary layout per record (48 bytes):
 *   [0-7]  int64 worker_id
 *   [8-15] int64 active_connections
 *  [16-23] int64 active_requests
 *  [24-31] int64 pending_writes
 *  [32-39] int64 pressure_score * 1e6 (fixed-point)
 *  [40-47] int64 updated_at (microseconds since epoch)
 */
final class SharedWorkerTable
{
    private const RECORD_SIZE = 48;
    private const MAX_WORKERS = 64;

    private $shm = null;
    private ?SemaphoreLock $lock;
    private bool $useShmop;
    private $sysvShm = null;
    private int $shmKey;

    public function __construct(int $key = 0, int $maxWorkers = self::MAX_WORKERS)
    {
        $this->shmKey = $key ?: ftok(__FILE__, 'a');
        $size = self::RECORD_SIZE * min($maxWorkers, self::MAX_WORKERS);

        $this->useShmop = extension_loaded('shmop');

        if ($this->useShmop) {
            $this->shm = @shmop_open($this->shmKey, 'c', 0644, $size);
        } elseif (extension_loaded('sysvshm')) {
            $this->sysvShm = @shm_attach($this->shmKey, $size, 0644);
        }
        // else: no shared memory — all operations become no-ops

        $this->lock = new SemaphoreLock($this->shmKey ^ 0xFF00);
    }

    public function update(int $workerId, WorkerLocalStats $stats): void
    {
        if ($workerId < 1 || $workerId > self::MAX_WORKERS) {
            return;
        }

        $offset = ($workerId - 1) * self::RECORD_SIZE;
        $packed = pack('P6',
            $workerId,
            $stats->activeConnections,
            $stats->activeRequests,
            $stats->pendingWrites,
            (int) ($stats->runtimePressureScore * 1e6),
            (int) (microtime(true) * 1e6)
        );

        $this->lock->locked(function () use ($offset, $packed) {
            if ($this->useShmop && $this->shm) {
                shmop_write($this->shm, $packed, $offset);
            } elseif ($this->sysvShm) {
                shm_put_var($this->sysvShm, $offset, $packed);
            }
        });
    }

    public function read(int $workerId): ?array
    {
        if ($workerId < 1 || $workerId > self::MAX_WORKERS) {
            return null;
        }

        $offset = ($workerId - 1) * self::RECORD_SIZE;

        return $this->lock->locked(function () use ($offset, $workerId) {
            $raw = null;

            if ($this->useShmop && $this->shm) {
                $raw = shmop_read($this->shm, $offset, self::RECORD_SIZE);
            } elseif ($this->sysvShm && shm_has_var($this->sysvShm, $offset)) {
                $raw = shm_get_var($this->sysvShm, $offset);
            }

            if (!$raw) {
                return null;
            }

            $u = unpack('P6', $raw);
            if (!$u || $u[6] === 0) {
                return null;
            }

            return [
                'worker_id'          => $u[1],
                'active_connections' => $u[2],
                'active_requests'    => $u[3],
                'pending_writes'     => $u[4],
                'pressure_score'     => $u[5] / 1e6,
                'updated_at'         => $u[6] / 1e6,
            ];
        });
    }

    public function readAll(): array
    {
        $result = [];
        for ($i = 1; $i <= self::MAX_WORKERS; $i++) {
            $r = $this->read($i);
            if ($r !== null && $r['updated_at'] > 0) {
                $result[] = $r;
            }
        }
        return $result;
    }

    /**
     * Average pressure across all active workers.
     */
    public function averagePressure(): float
    {
        $all = $this->readAll();
        if (!$all) {
            return 0.0;
        }
        $sum = array_sum(array_column($all, 'pressure_score'));
        return $sum / count($all);
    }

    public function isAvailable(): bool
    {
        return ($this->useShmop && $this->shm !== null)
            || $this->sysvShm !== null;
    }

    public function cleanup(): void
    {
        if ($this->useShmop && $this->shm) {
            shmop_delete($this->shm);
        } elseif ($this->sysvShm) {
            shm_remove($this->sysvShm);
        }
    }
}
