<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Runtime\Observability;

/**
 * Runtime dashboard.
 * Provides real-time visibility into worker health, queue depth, throughput, and system state.
 */
class Dashboard
{
    private RuntimeMetrics $metrics;
    private HealthMonitor $health;
    private array $workers = [];
    private array $queues = [];

    public function __construct(RuntimeMetrics $metrics, HealthMonitor $health)
    {
        $this->metrics = $metrics;
        $this->health = $health;
    }

    public function registerWorker(int $id, array $info): void
    {
        $this->workers[$id] = array_merge($info, [
            'registered_at' => microtime(true),
            'last_seen' => microtime(true),
        ]);
    }

    public function updateWorker(int $id, array $info): void
    {
        if (isset($this->workers[$id])) {
            $this->workers[$id] = array_merge($this->workers[$id], $info, [
                'last_seen' => microtime(true),
            ]);
        }
    }

    public function removeWorker(int $id): void
    {
        unset($this->workers[$id]);
    }

    public function registerQueue(string $name, object $queue): void
    {
        $this->queues[$name] = $queue;
    }

    public function getSnapshot(): array
    {
        $health = $this->health->check();
        $metrics = $this->metrics->toArray();

        return [
            'timestamp' => microtime(true),
            'health' => $health,
            'metrics' => $metrics,
            'workers' => $this->getWorkerStats(),
            'queues' => $this->getQueueStats(),
            'system' => $this->getSystemStats(),
        ];
    }

    public function render(): string
    {
        $snapshot = $this->getSnapshot();

        $output = [];
        $output[] = $this->renderHeader();
        $output[] = $this->renderHealth($snapshot['health']);
        $output[] = $this->renderMetrics($snapshot['metrics']);
        $output[] = $this->renderWorkers($snapshot['workers']);
        $output[] = $this->renderQueues($snapshot['queues']);
        $output[] = $this->renderSystem($snapshot['system']);

        return implode("\n", $output);
    }

    public function renderCompact(): string
    {
        $snapshot = $this->getSnapshot();
        $health = $snapshot['health'];
        $metrics = $snapshot['metrics'];

        $status = match ($health['state']) {
            'healthy' => '✓',
            'degraded' => '⚠',
            'unhealthy' => '✗',
            default => '?',
        };

        return sprintf(
            "%s | Workers: %d | Queue: %d | Completed: %d | Failed: %d | Throughput: %.2f/s | Memory: %.1fMB",
            $status,
            $metrics['gauges']['active_workers'],
            $metrics['gauges']['queue_depth'],
            $metrics['counters']['jobs_completed'],
            $metrics['counters']['jobs_failed'],
            $metrics['computed']['throughput'],
            $metrics['computed']['memory_usage_mb']
        );
    }

    private function renderHeader(): string
    {
        return str_repeat('=', 80) . "\n" .
            "  nexphant RUNTIME DASHBOARD\n" .
            str_repeat('=', 80);
    }

    private function renderHealth(array $health): string
    {
        $stateIcon = match ($health['state']) {
            'healthy' => '✓',
            'degraded' => '⚠',
            'unhealthy' => '✗',
            default => '?',
        };

        $output = "\nHEALTH: {$stateIcon} {$health['state']}";

        if (!empty($health['degradation_reasons'])) {
            $output .= " (" . implode(', ', $health['degradation_reasons']) . ")";
        }

        return $output;
    }

    private function renderMetrics(array $metrics): string
    {
        $uptime = gmdate('H:i:s', (int) $metrics['uptime']);

        $output = "\nMETRICS:\n";
        $output .= sprintf("  Uptime:       %s\n", $uptime);
        $output .= sprintf("  Throughput:   %.2f jobs/s\n", $metrics['computed']['throughput']);
        $output .= sprintf("  Success Rate: %.1f%%\n", $metrics['computed']['success_rate']);
        $output .= sprintf("  Completed:    %d\n", $metrics['counters']['jobs_completed']);
        $output .= sprintf("  Failed:       %d\n", $metrics['counters']['jobs_failed']);
        $output .= sprintf("  Retried:      %d\n", $metrics['counters']['jobs_retried']);
        $output .= sprintf("  Queue Depth:  %d\n", $metrics['gauges']['queue_depth']);

        return $output;
    }

    private function renderWorkers(array $workers): string
    {
        $output = "\nWORKERS: {$workers['active']}/{$workers['total']}\n";

        foreach ($workers['list'] as $worker) {
            $status = $worker['status'] ?? 'unknown';
            $jobsProcessed = $worker['jobs_processed'] ?? 0;
            $uptime = isset($worker['uptime']) ? gmdate('H:i:s', (int) $worker['uptime']) : 'N/A';

            $output .= sprintf(
                "  [%d] %s | Jobs: %d | Uptime: %s\n",
                $worker['id'],
                $status,
                $jobsProcessed,
                $uptime
            );
        }

        return $output;
    }

    private function renderQueues(array $queues): string
    {
        if (empty($queues)) {
            return "\nQUEUES: none";
        }

        $output = "\nQUEUES:\n";

        foreach ($queues as $name => $stats) {
            $output .= sprintf(
                "  %s: depth=%d, pending=%d, running=%d\n",
                $name,
                $stats['depth'] ?? 0,
                $stats['pending'] ?? 0,
                $stats['running'] ?? 0
            );
        }

        return $output;
    }

    private function renderSystem(array $system): string
    {
        $output = "\nSYSTEM:\n";
        $output .= sprintf(
            "  Memory:       %.1f MB / %.1f MB\n",
            $system['memory_usage_mb'],
            $system['memory_peak_mb']
        );

        if (isset($system['cpu_load'])) {
            $output .= sprintf(
                "  CPU Load:     %.2f (1m), %.2f (5m), %.2f (15m)\n",
                $system['cpu_load'][0],
                $system['cpu_load'][1],
                $system['cpu_load'][2]
            );
        }

        if (isset($system['loop_lag_ms'])) {
            $output .= sprintf("  Loop Lag:     %.2f ms\n", $system['loop_lag_ms']);
        }

        return $output;
    }

    private function getWorkerStats(): array
    {
        $active = 0;
        $list = [];

        foreach ($this->workers as $id => $worker) {
            if (isset($worker['status']) && $worker['status'] === 'active') {
                $active++;
            }

            $list[] = array_merge(['id' => $id], $worker);
        }

        return [
            'total' => count($this->workers),
            'active' => $active,
            'list' => $list,
        ];
    }

    private function getQueueStats(): array
    {
        $stats = [];

        foreach ($this->queues as $name => $queue) {
            if (method_exists($queue, 'status')) {
                $stats[$name] = $queue->status();
            }
        }

        return $stats;
    }

    private function getSystemStats(): array
    {
        $metrics = $this->metrics->toArray();

        $stats = [
            'memory_usage_mb' => $metrics['computed']['memory_usage_mb'],
            'memory_peak_mb' => $metrics['computed']['memory_peak_mb'],
        ];

        if (function_exists('sys_getloadavg')) {
            $stats['cpu_load'] = sys_getloadavg();
        }

        if (isset($metrics['gauges']['loop_lag_ms'])) {
            $stats['loop_lag_ms'] = $metrics['gauges']['loop_lag_ms'];
        }

        return $stats;
    }
}
