<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Runtime\Observability;

/**
 * Runtime metrics collector.
 * Tracks worker health, fiber activity, queue depth, throughput, and degradation state.
 */
class RuntimeMetrics
{
    private array $metrics = [];
    private float $startTime;
    private array $counters = [];
    private array $gauges = [];
    private array $timers = [];

    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->reset();
    }

    public function reset(): void
    {
        $this->counters = [
            'jobs_enqueued' => 0,
            'jobs_completed' => 0,
            'jobs_failed' => 0,
            'jobs_retried' => 0,
            'fibers_spawned' => 0,
            'fibers_completed' => 0,
            'timers_created' => 0,
            'timers_fired' => 0,
            'errors' => 0,
            'panics' => 0,
        ];

        $this->gauges = [
            'active_workers' => 0,
            'active_fibers' => 0,
            'queue_depth' => 0,
            'active_timers' => 0,
            'memory_usage' => 0,
            'memory_peak' => 0,
            'loop_lag_ms' => 0,
        ];

        $this->timers = [
            'job_duration_total' => 0.0,
            'job_duration_count' => 0,
        ];
    }

    public function incrementCounter(string $name, int $value = 1): void
    {
        if (!isset($this->counters[$name])) {
            $this->counters[$name] = 0;
        }
        $this->counters[$name] += $value;
    }

    public function setGauge(string $name, float|int $value): void
    {
        $this->gauges[$name] = $value;
    }

    public function recordTiming(string $name, float $duration): void
    {
        if (!isset($this->timers[$name . '_total'])) {
            $this->timers[$name . '_total'] = 0.0;
            $this->timers[$name . '_count'] = 0;
        }
        $this->timers[$name . '_total'] += $duration;
        $this->timers[$name . '_count']++;
    }

    public function updateMemory(): void
    {
        $this->gauges['memory_usage'] = memory_get_usage(true);
        $this->gauges['memory_peak'] = memory_get_peak_usage(true);
    }

    public function getCounter(string $name): int
    {
        return $this->counters[$name] ?? 0;
    }

    public function getGauge(string $name): float|int
    {
        return $this->gauges[$name] ?? 0;
    }

    public function getAverageTiming(string $name): float
    {
        $total = $this->timers[$name . '_total'] ?? 0.0;
        $count = $this->timers[$name . '_count'] ?? 0;
        return $count > 0 ? $total / $count : 0.0;
    }

    public function getUptime(): float
    {
        return microtime(true) - $this->startTime;
    }

    public function getThroughput(): float
    {
        $uptime = $this->getUptime();
        return $uptime > 0 ? $this->counters['jobs_completed'] / $uptime : 0.0;
    }

    public function getSuccessRate(): float
    {
        $total = $this->counters['jobs_completed'] + $this->counters['jobs_failed'];
        return $total > 0 ? ($this->counters['jobs_completed'] / $total) * 100 : 100.0;
    }

    public function toArray(): array
    {
        $this->updateMemory();

        return [
            'uptime' => $this->getUptime(),
            'counters' => $this->counters,
            'gauges' => $this->gauges,
            'timers' => [
                'job_duration_avg' => $this->getAverageTiming('job_duration'),
            ],
            'computed' => [
                'throughput' => $this->getThroughput(),
                'success_rate' => $this->getSuccessRate(),
                'memory_usage_mb' => round($this->gauges['memory_usage'] / 1024 / 1024, 2),
                'memory_peak_mb' => round($this->gauges['memory_peak'] / 1024 / 1024, 2),
            ],
        ];
    }

    public function snapshot(): array
    {
        return $this->toArray();
    }
}
