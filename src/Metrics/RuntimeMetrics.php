<?php

declare(strict_types=1);

namespace Nexph\Runtime\Metrics;

use Nexph\Core\Ownership\OwnerRegistry;
use Nexph\Core\Resource\ResourceRegistry;

/**
 * Runtime metrics tracker for tasks, fibers, and resources
 */
final class RuntimeMetrics
{
    private static ?self $instance = null;
    private MetricsCollector $collector;
    private array $taskTimings = [];

    private function __construct()
    {
        $this->collector = MetricsCollector::instance();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function taskStarted(string $taskId, string $type, array $labels = []): void
    {
        $this->taskTimings[$taskId] = ['started' => microtime(true), 'queued' => $labels['queued_at'] ?? null];
        $this->collector->increment('task_started_total', 1, array_merge(['type' => $type], $labels));
    }

    public function taskCompleted(string $taskId, string $type, array $labels = []): void
    {
        if (isset($this->taskTimings[$taskId])) {
            $timing = $this->taskTimings[$taskId];
            $started = is_array($timing) ? $timing['started'] : $timing;
            $duration = (microtime(true) - $started) * 1000;
            $this->collector->observe('task_duration_ms', $duration, array_merge(['type' => $type], $labels));
            
            if (is_array($timing) && $timing['queued'] !== null) {
                $queueWait = ($started - $timing['queued']) * 1000;
                $this->collector->observe('task_queue_wait_ms', $queueWait, array_merge(['type' => $type], $labels));
            }
            
            unset($this->taskTimings[$taskId]);
        }
        $this->collector->increment('task_completed_total', 1, array_merge(['type' => $type], $labels));
    }

    public function taskFailed(string $taskId, string $type, array $labels = []): void
    {
        if (isset($this->taskTimings[$taskId])) {
            unset($this->taskTimings[$taskId]);
        }
        $this->collector->increment('task_failed_total', 1, array_merge(['type' => $type], $labels));
    }

    public function taskCancelled(string $taskId, string $type, array $labels = []): void
    {
        if (isset($this->taskTimings[$taskId])) {
            unset($this->taskTimings[$taskId]);
        }
        $this->collector->increment('task_cancelled_total', 1, array_merge(['type' => $type], $labels));
    }

    public function updateFiberMetrics(): void
    {
        $this->collector->gauge('active_fibers', count($this->taskTimings));
        
        if (class_exists('\Nexph\Runtime\Fiber\FiberRegistry')) {
            $stats = \Nexph\Runtime\Fiber\FiberRegistry::instance()->stats();
            $this->collector->gauge('suspended_fibers', $stats['suspended']);
            $this->collector->gauge('fibers_running', $stats['running']);
            $this->collector->gauge('fibers_completed', $stats['completed']);
            $this->collector->gauge('fibers_failed', $stats['failed']);
            $this->collector->gauge('fibers_cancelled', $stats['cancelled']);
        }
    }

    public function updateResourceMetrics(): void
    {
        $stats = ResourceRegistry::instance()->stats();
        $this->collector->gauge('resource_leaks_total', $stats['leaked']);
        
        foreach ($stats['by_type'] as $type => $typeStats) {
            $this->collector->gauge('resources_total', $typeStats['total'], ['type' => $type]);
            $this->collector->gauge('resources_leaked', $typeStats['leaked'], ['type' => $type]);
        }
    }

    public function updateOwnerMetrics(): void
    {
        $stats = OwnerRegistry::instance()->stats();
        $this->collector->gauge('owners_alive', $stats['alive']);
        
        foreach ($stats['by_type'] as $type => $typeStats) {
            $this->collector->gauge('owners_total', $typeStats['total'], ['type' => $type]);
            $this->collector->gauge('owners_alive_by_type', $typeStats['alive'], ['type' => $type]);
        }
    }

    public function snapshot(): array
    {
        $this->updateFiberMetrics();
        $this->updateResourceMetrics();
        $this->updateOwnerMetrics();
        
        return $this->collector->all();
    }

    public function reset(): void
    {
        $this->taskTimings = [];
        $this->collector->reset();
    }
}
