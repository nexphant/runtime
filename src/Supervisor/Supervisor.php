<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Runtime\Supervisor;

use Nexph\Runtime\Runtime;
use Nexph\Runtime\Observability\Logger;

/**
 * Lightweight worker supervisor.
 * Handles auto-restart, graceful shutdown, panic recovery, lock cleanup, and health monitoring.
 */
class Supervisor
{
    private array $workers = [];
    private array $config = [];
    private bool $running = false;
    private ?Logger $logger = null;
    private int $nextWorkerId = 1;

    public function __construct(array $config = [], ?Logger $logger = null)
    {
        $this->config = array_merge([
            'max_restarts' => 3,
            'restart_window' => 60,
            'restart_delay' => 5,
            'health_check_interval' => 30,
            'shutdown_timeout' => 30,
            'panic_recovery' => true,
        ], $config);

        $this->logger = $logger ?? new Logger();
    }

    /**
     * Register worker process.
     */
    public function register(string $name, callable $worker, array $config = []): int
    {
        $id = $this->nextWorkerId++;

        $this->workers[$id] = [
            'id' => $id,
            'name' => $name,
            'worker' => $worker,
            'config' => array_merge($this->config, $config),
            'status' => 'registered',
            'pid' => null,
            'started_at' => null,
            'restarts' => [],
            'last_health_check' => null,
            'health_status' => 'unknown',
        ];

        $this->logger->info("Worker registered", ['worker_id' => $id, 'name' => $name]);

        return $id;
    }

    /**
     * Start all workers.
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        $this->logger->info("Supervisor starting", ['workers' => count($this->workers)]);

        foreach ($this->workers as $id => $worker) {
            $this->startWorker($id);
        }

        if (Runtime::available()) {
            $this->superviseAsync();
        } else {
            $this->superviseSync();
        }
    }

    /**
     * Stop all workers gracefully.
     */
    public function stop(): void
    {
        $this->running = false;
        $this->logger->info("Supervisor stopping");

        foreach ($this->workers as $id => $worker) {
            $this->stopWorker($id);
        }
    }

    /**
     * Restart specific worker.
     */
    public function restart(int $workerId): void
    {
        if (!isset($this->workers[$workerId])) {
            return;
        }

        $this->logger->info("Restarting worker", ['worker_id' => $workerId]);

        $this->stopWorker($workerId);

        if (Runtime::available()) {
            Runtime::sleep($this->config['restart_delay']);
        } else {
            sleep($this->config['restart_delay']);
        }

        $this->startWorker($workerId);
    }

    /**
     * Get worker status.
     */
    public function getStatus(int $workerId): ?array
    {
        return $this->workers[$workerId] ?? null;
    }

    /**
     * Get all workers status.
     */
    public function getAllStatus(): array
    {
        return array_values($this->workers);
    }

    /**
     * Start individual worker.
     */
    private function startWorker(int $workerId): void
    {
        $worker = &$this->workers[$workerId];

        if (!$this->canRestart($worker)) {
            $this->logger->error("Worker restart limit exceeded", ['worker_id' => $workerId]);
            $worker['status'] = 'failed';
            return;
        }

        try {
            $worker['status'] = 'starting';
            $worker['started_at'] = time();

            if (Runtime::available()) {
                $coroutine = Runtime::spawn(function () use ($workerId) {
                    $this->runWorker($workerId);
                });

                $worker['coroutine'] = $coroutine;
                $worker['pid'] = getmypid();
            } else {
                $worker['pid'] = getmypid();
            }

            $worker['status'] = 'running';
            $this->recordRestart($worker);

            $this->logger->info("Worker started", [
                'worker_id' => $workerId,
                'name' => $worker['name'],
                'pid' => $worker['pid'],
            ]);

        } catch (\Throwable $e) {
            $worker['status'] = 'failed';
            $worker['error'] = $e->getMessage();

            $this->logger->error("Worker start failed", [
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stop individual worker.
     */
    private function stopWorker(int $workerId): void
    {
        $worker = &$this->workers[$workerId];

        if ($worker['status'] !== 'running') {
            return;
        }

        $this->logger->info("Stopping worker", ['worker_id' => $workerId]);

        $worker['status'] = 'stopping';

        try {
            if (isset($worker['coroutine'])) {
                $timeout = $worker['config']['shutdown_timeout'];
                $start = time();

                while (!$worker['coroutine']->isFinished() && (time() - $start) < $timeout) {
                    if (Runtime::available()) {
                        Runtime::sleep(0.1);
                    } else {
                        usleep(100000);
                    }
                }

                if (!$worker['coroutine']->isFinished()) {
                    $this->logger->warning("Worker shutdown timeout", ['worker_id' => $workerId]);
                }
            }

            $this->cleanupWorker($worker);

            $worker['status'] = 'stopped';
            $worker['stopped_at'] = time();

            $this->logger->info("Worker stopped", ['worker_id' => $workerId]);

        } catch (\Throwable $e) {
            $this->logger->error("Worker stop failed", [
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run worker with panic recovery.
     */
    private function runWorker(int $workerId): void
    {
        $worker = &$this->workers[$workerId];

        try {
            ($worker['worker'])($workerId);

            $worker['status'] = 'completed';

        } catch (\Throwable $e) {
            $worker['status'] = 'crashed';
            $worker['error'] = $e->getMessage();

            $this->logger->error("Worker crashed", [
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($worker['config']['panic_recovery'] && $this->running) {
                $this->logger->info("Attempting panic recovery", ['worker_id' => $workerId]);

                if (Runtime::available()) {
                    Runtime::sleep($worker['config']['restart_delay']);
                } else {
                    sleep($worker['config']['restart_delay']);
                }

                $this->startWorker($workerId);
            }
        }
    }

    /**
     * Supervise workers (async mode).
     */
    private function superviseAsync(): void
    {
        Runtime::spawn(function () {
            while ($this->running) {
                $this->checkWorkers();
                Runtime::sleep($this->config['health_check_interval']);
            }
        });

        Runtime::run();
    }

    /**
     * Supervise workers (sync mode).
     */
    private function superviseSync(): void
    {
        while ($this->running) {
            $this->checkWorkers();
            sleep($this->config['health_check_interval']);
        }
    }

    /**
     * Check worker health.
     */
    private function checkWorkers(): void
    {
        foreach ($this->workers as $id => $worker) {
            if ($worker['status'] === 'running') {
                $this->healthCheck($id);
            } elseif ($worker['status'] === 'crashed' && $this->running) {
                $this->restart($id);
            }
        }
    }

    /**
     * Perform health check on worker.
     */
    private function healthCheck(int $workerId): void
    {
        $worker = &$this->workers[$workerId];

        $worker['last_health_check'] = time();

        if (isset($worker['coroutine']) && $worker['coroutine']->isFinished()) {
            $worker['health_status'] = 'dead';
            $worker['status'] = 'crashed';

            $this->logger->warning("Worker health check failed - dead", ['worker_id' => $workerId]);

            if ($this->running) {
                $this->restart($workerId);
            }
        } else {
            $worker['health_status'] = 'healthy';
        }
    }

    /**
     * Check if worker can be restarted.
     */
    private function canRestart(array $worker): bool
    {
        $window = $worker['config']['restart_window'];
        $maxRestarts = $worker['config']['max_restarts'];
        $now = time();

        $recentRestarts = array_filter($worker['restarts'], function ($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        });

        return count($recentRestarts) < $maxRestarts;
    }

    /**
     * Record worker restart.
     */
    private function recordRestart(array &$worker): void
    {
        $worker['restarts'][] = time();

        $window = $worker['config']['restart_window'];
        $now = time();

        $worker['restarts'] = array_filter($worker['restarts'], function ($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        });
    }

    /**
     * Cleanup worker resources.
     */
    private function cleanupWorker(array $worker): void
    {
        // Lock cleanup placeholder
        // In production, this would clean up file locks, database locks, etc.

        $this->logger->debug("Worker cleanup completed", ['worker_id' => $worker['id']]);
    }
}
