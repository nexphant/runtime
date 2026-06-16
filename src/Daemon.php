<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Runtime;

class Daemon
{
    private bool $running = true;
    private int $startTime;
    private string $pidFile;
    private array $callbacks = [];
    private int $cycleCount = 0;
    private MemoryMonitor $memoryMonitor;

    public function __construct(string $name = 'nexphant')
    {
        $this->startTime = time();
        $this->pidFile = sys_get_temp_dir() . "/{$name}.pid";
        $this->memoryMonitor = new MemoryMonitor();
        $this->setupSignals();
    }

    private function setupSignals(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, [$this, 'shutdown']);
        pcntl_signal(SIGINT, [$this, 'shutdown']);
        pcntl_signal(SIGHUP, [$this, 'reload']);
    }

    public function shutdown(int $signal = 0): void
    {
        $this->running = false;
        $this->log("Shutdown signal received ({$signal})");
    }

    public function reload(int $signal = 0): void
    {
        $this->log("Reload signal received");
        foreach ($this->callbacks['reload'] ?? [] as $cb) {
            $cb();
        }
    }

    public function onReload(callable $callback): self
    {
        $this->callbacks['reload'][] = $callback;
        return $this;
    }

    public function onShutdown(callable $callback): self
    {
        $this->callbacks['shutdown'][] = $callback;
        return $this;
    }

    public function onCycle(callable $callback): self
    {
        $this->callbacks['cycle'][] = $callback;
        return $this;
    }

    public function start(): void
    {
        if (!$this->acquireLock()) {
            $this->log("Another instance is running");
            exit(1);
        }
        $this->log("Daemon started (PID: " . getmypid() . ")");
    }

    public function run(int $sleepMs = 1000): void
    {
        while ($this->running) {
            $this->cycleCount++;
            $this->memoryMonitor->sample();

            foreach ($this->callbacks['cycle'] ?? [] as $cb) {
                if (!$this->running)
                    break;
                $cb($this->cycleCount);
            }

            if ($this->memoryMonitor->detectLeak()) {
                $this->log("Memory leak detected: " . $this->memoryMonitor->getReport());
            }

            if ($this->running) {
                usleep($sleepMs * 1000);
            }
        }
        $this->cleanup();
    }

    private function cleanup(): void
    {
        foreach ($this->callbacks['shutdown'] ?? [] as $cb) {
            $cb();
        }
        $this->releaseLock();
        $this->log("Daemon stopped");
    }

    private function acquireLock(): bool
    {
        if (file_exists($this->pidFile)) {
            $pid = (int) file_get_contents($this->pidFile);
            if ($pid > 0 && posix_kill($pid, 0)) {
                return false;
            }
        }
        file_put_contents($this->pidFile, getmypid());
        return true;
    }

    private function releaseLock(): void
    {
        if (file_exists($this->pidFile)) {
            unlink($this->pidFile);
        }
    }

    public function uptime(): int
    {
        return time() - $this->startTime;
    }

    public function cycles(): int
    {
        return $this->cycleCount;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function getMemoryMonitor(): MemoryMonitor
    {
        return $this->memoryMonitor;
    }

    private function log(string $message): void
    {
        $time = date('Y-m-d H:i:s');
        error_log("[{$time}] [Daemon] {$message}");
    }
}
