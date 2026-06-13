<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Runtime;

class ProcessManager
{
    private array $workers = [];
    private int $maxWorkers;
    private string $script;
    private bool $running = true;

    public function __construct(string $script, int $maxWorkers = 4)
    {
        $this->script = $script;
        $this->maxWorkers = $maxWorkers;
        $this->setupSignals();
    }

    private function setupSignals(): void
    {
        if (!function_exists('pcntl_signal'))
            return;
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, [$this, 'shutdown']);
        pcntl_signal(SIGINT, [$this, 'shutdown']);
        pcntl_signal(SIGCHLD, [$this, 'reap']);
    }

    public function shutdown(): void
    {
        $this->running = false;
        foreach ($this->workers as $pid => $info) {
            posix_kill($pid, SIGTERM);
        }
    }

    public function reap(): void
    {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            unset($this->workers[$pid]);
            $this->log("Worker {$pid} exited");
        }
    }

    public function run(): void
    {
        $this->log("Process manager started, max workers: {$this->maxWorkers}");

        while ($this->running) {
            $this->reap();

            while (count($this->workers) < $this->maxWorkers && $this->running) {
                $this->spawn();
            }

            sleep(1);
        }

        $this->waitAll();
        $this->log("Process manager stopped");
    }

    private function spawn(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->log("Fork failed");
            return;
        }
        if ($pid === 0) {
            // Child
            pcntl_exec('/usr/bin/php', [$this->script]);
            exit(1);
        }
        // Parent
        $this->workers[$pid] = ['started' => time()];
        $this->log("Spawned worker {$pid}");
    }

    private function waitAll(): void
    {
        $timeout = 30;
        $start = time();
        while (count($this->workers) > 0 && (time() - $start) < $timeout) {
            $this->reap();
            usleep(100000);
        }
        // Force kill remaining
        foreach ($this->workers as $pid => $info) {
            posix_kill($pid, SIGKILL);
        }
    }

    public function getWorkerCount(): int
    {
        return count($this->workers);
    }

    private function log(string $msg): void
    {
        error_log("[" . date('Y-m-d H:i:s') . "] [ProcessManager] {$msg}");
    }
}
