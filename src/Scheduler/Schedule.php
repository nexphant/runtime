<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Runtime\Scheduler;

use Nexph\Runtime\Runtime;
use Nexph\Runtime\Timer;

/**
 * Coroutine-aware scheduler/cron subsystem.
 * Uses existing timer and event-loop infrastructure with explicit APIs.
 */
class Schedule {
    private array $tasks = [];
    private array $timers = [];
    private bool $running = false;
    private int $nextTaskId = 1;
    
    public function __construct() {}
    
    /**
     * Schedule task to run every minute.
     */
    public function everyMinute(callable $callback): ScheduledTask {
        return $this->cron('* * * * *', $callback);
    }
    
    /**
     * Schedule task to run every 5 minutes.
     */
    public function everyFiveMinutes(callable $callback): ScheduledTask {
        return $this->cron('*/5 * * * *', $callback);
    }
    
    /**
     * Schedule task to run every 15 minutes.
     */
    public function everyFifteenMinutes(callable $callback): ScheduledTask {
        return $this->cron('*/15 * * * *', $callback);
    }
    
    /**
     * Schedule task to run every 30 minutes.
     */
    public function everyThirtyMinutes(callable $callback): ScheduledTask {
        return $this->cron('*/30 * * * *', $callback);
    }
    
    /**
     * Schedule task to run hourly.
     */
    public function hourly(callable $callback): ScheduledTask {
        return $this->cron('0 * * * *', $callback);
    }
    
    /**
     * Schedule task to run daily at specific time.
     */
    public function daily(string $time, callable $callback): ScheduledTask {
        [$hour, $minute] = explode(':', $time);
        return $this->cron("{$minute} {$hour} * * *", $callback);
    }
    
    /**
     * Schedule task to run weekly.
     */
    public function weekly(int $dayOfWeek, string $time, callable $callback): ScheduledTask {
        [$hour, $minute] = explode(':', $time);
        return $this->cron("{$minute} {$hour} * * {$dayOfWeek}", $callback);
    }
    
    /**
     * Schedule task with cron expression.
     */
    public function cron(string $expression, callable $callback): ScheduledTask {
        $task = new ScheduledTask($this->nextTaskId++, $expression, $callback);
        $this->tasks[$task->id] = $task;
        return $task;
    }
    
    /**
     * Schedule task to run at fixed interval.
     */
    public function every(int $seconds, callable $callback): ScheduledTask {
        $task = new ScheduledTask($this->nextTaskId++, null, $callback);
        $task->interval = $seconds;
        $this->tasks[$task->id] = $task;
        return $task;
    }
    
    /**
     * Remove scheduled task.
     */
    public function cancel(int $taskId): void {
        unset($this->tasks[$taskId]);
        
        if (isset($this->timers[$taskId])) {
            if (Runtime::available()) {
                Runtime::loop()->cancelTimer($this->timers[$taskId]);
            }
            unset($this->timers[$taskId]);
        }
    }
    
    /**
     * Run scheduler (blocking).
     */
    public function run(): void {
        $this->running = true;
        
        if (Runtime::available()) {
            $this->runAsync();
        } else {
            $this->runSync();
        }
    }
    
    /**
     * Stop scheduler.
     */
    public function stop(): void {
        $this->running = false;
        
        // Integrate with DrainController
        if (class_exists('\Nexph\Core\Drain\DrainController')) {
            \Nexph\Core\Drain\DrainController::instance()->stopAccepting();
        }
        
        foreach ($this->timers as $timerId) {
            if (Runtime::available()) {
                Runtime::loop()->cancelTimer($timerId);
            }
        }
        
        $this->timers = [];
    }
    
    /**
     * Get all scheduled tasks.
     */
    public function getTasks(): array {
        return array_values($this->tasks);
    }
    
    /**
     * Get task by ID.
     */
    public function getTask(int $taskId): ?ScheduledTask {
        return $this->tasks[$taskId] ?? null;
    }
    
    /**
     * Run scheduler in async mode (coroutine-based).
     */
    private function runAsync(): void {
        foreach ($this->tasks as $task) {
            if ($task->interval !== null) {
                $timerId = Runtime::loop()->timer($task->interval, function() use ($task) {
                    $this->executeTask($task);
                }, true);
                
                $this->timers[$task->id] = $timerId;
            } else {
                Runtime::spawn(function() use ($task) {
                    $this->cronLoop($task);
                });
            }
        }
        
        Runtime::run();
    }
    
    /**
     * Run scheduler in sync mode (blocking).
     */
    private function runSync(): void {
        $lastCheck = time();
        
        while ($this->running) {
            $now = time();
            
            if ($now > $lastCheck) {
                foreach ($this->tasks as $task) {
                    if ($task->shouldRun($now)) {
                        $this->executeTask($task);
                        $task->markRan($now);
                    }
                }
                
                $lastCheck = $now;
            }
            
            sleep(1);
        }
    }
    
    /**
     * Cron loop for async mode.
     */
    private function cronLoop(ScheduledTask $task): void {
        while ($this->running) {
            $now = time();
            
            if ($task->shouldRun($now)) {
                $this->executeTask($task);
                $task->markRan($now);
            }
            
            Runtime::sleep(1.0);
        }
    }
    
    /**
     * Execute scheduled task with isolation.
     */
    private function executeTask(ScheduledTask $task): void {
        if (!$task->enabled) {
            return;
        }

        if ($task->isRunning) {
            $task->skippedCount++;
            return;
        }
        
        $task->isRunning = true;
        $task->lastRun = time();
        $task->runCount++;
        
        // Capture current context
        $context = Runtime::available() ? Runtime::context() : null;
        
        $runner = function () use ($task, $context) {
            $startTime = microtime(true);

            try {
                // Restore context for task execution
                if ($context !== null && Runtime::available()) {
                    Runtime::withContext($context, $task->callback);
                } else {
                    ($task->callback)();
                }
                $task->lastDuration = microtime(true) - $startTime;
                $task->lastError = null;
            } catch (\Throwable $e) {
                $this->handleTaskError($task, $e);
            } finally {
                $task->isRunning = false;
            }
        };

        if (Runtime::available() && $task->async) {
            Runtime::spawn($runner);
            return;
        }

        $runner();
    }

    private function handleTaskError(ScheduledTask $task, \Throwable $e): void {
            $task->lastError = $e->getMessage();
            $task->errorCount++;
            
            if ($task->onError) {
                try {
                    ($task->onError)($e, $task);
                } catch (\Throwable $ignored) {}
            }
    }
}
