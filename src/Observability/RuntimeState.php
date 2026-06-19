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

use Nexphant\Queue\QueueFactory;
use Nexphant\Runtime\Runtime;

class RuntimeState
{
    public static function snapshot(?string $driver = null, array $options = []): array
    {
        $driver = $driver ?? getenv('QUEUE_DRIVER') ?: 'file';
        $queue = null;
        $queueError = null;
        try {
            $queue = QueueFactory::create($driver);
            $queueStatus = $queue->status();
            $deadLetters = count($queue->driver()->getDeadLetters(1000));
        } catch (\Throwable $e) {
            $queueStatus = ['running' => false, 'workers' => 0, 'depth' => 0, 'metrics' => []];
            $deadLetters = 0;
            $queueError = $e->getMessage();
        }

        $server = self::serverSnapshot($options);
        $runtimeAvailable = Runtime::available() || $server['running'];
        $metrics = new RuntimeMetrics();
        $metrics->setGauge('queue_depth', (int) ($queueStatus['depth'] ?? 0));
        $metrics->setGauge('active_workers', (int) ($queueStatus['workers'] ?? 0));
        $metrics->setGauge('http_workers', (int) ($server['workers_reporting'] ?? 0));
        $metrics->setGauge('http_active_connections', (int) ($server['active_connections'] ?? 0));
        $metrics->setGauge('active_fibers', self::activeFibers());
        $metrics->setGauge('active_timers', max(self::activeTimers(), (int) ($server['loop']['timers'] ?? 0)));
        $metrics->setGauge('dead_letter_count', $deadLetters);
        $metrics->setGauge('cpu_load_1m', self::load()[0] ?? 0);
        $metrics->setGauge('loop_lag_ms', (float) ($server['loop']['lag_ms'] ?? 0));

        $data = $metrics->toArray();
        $data['gauges']['memory_usage'] = max((int) $data['gauges']['memory_usage'], (int) ($server['memory']['current'] ?? 0));
        $data['gauges']['memory_peak'] = max((int) $data['gauges']['memory_peak'], (int) ($server['memory']['peak'] ?? 0));
        $data['computed']['memory_usage_mb'] = round($data['gauges']['memory_usage'] / 1024 / 1024, 2);
        $data['computed']['memory_peak_mb'] = round($data['gauges']['memory_peak'] / 1024 / 1024, 2);
        $data['runtime'] = [
            'mode' => $server['running'] ? 'server' : ($runtimeAvailable ? 'adaptive' : 'stateless'),
            'available' => $runtimeAvailable,
            'capabilities' => Runtime::capabilities(),
            'degradation_state' => $runtimeAvailable ? 'none' : 'fallback_stateless',
        ];
        $data['queue'] = [
            'driver' => $driver,
            'running' => (bool) ($queueStatus['running'] ?? false),
            'workers' => (int) ($queueStatus['workers'] ?? 0),
            'depth' => (int) ($queueStatus['depth'] ?? 0),
            'dead_letters' => $deadLetters,
            'error' => $queueError,
            'metrics' => $queueStatus['metrics'] ?? [],
        ];
        $data['system'] = [
            'memory_usage' => max(memory_get_usage(true), (int) ($server['memory']['current'] ?? 0)),
            'memory_peak' => max(memory_get_peak_usage(true), (int) ($server['memory']['peak'] ?? 0)),
            'memory_limit' => ini_get('memory_limit'),
            'cpu_load' => self::load(),
            'php_sapi' => PHP_SAPI,
        ];
        $data['server'] = $server;
        return $data;
    }

    public static function serverSnapshot(array $options = []): array
    {
        $port = isset($options['port']) ? (int) $options['port'] : (int) (getenv('PORT') ?: 0);
        $dirs = [];
        if ($port > 0) {
            $dirs[] = sys_get_temp_dir() . '/nexphant-http-' . $port;
        } else {
            $dirs = glob(sys_get_temp_dir() . '/nexphant-http-*') ?: [];
        }

        $servers = [];
        foreach ($dirs as $dir) {
            $stats = self::readServerStats($dir);
            if ($stats['running']) {
                $servers[] = $stats;
            }
        }

        usort($servers, fn(array $a, array $b) => ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0));
        return $servers[0] ?? self::emptyServer($port);
    }

    private static function readServerStats(string $dir): array
    {
        $now = microtime(true);
        $workers = [];
        $totalRequests = 0;
        $totalConnections = 0;
        $activeRequests = 0;
        $activeConnections = 0;
        $memoryCurrent = 0;
        $memoryPeak = 0;
        $uptime = 0.0;
        $updatedAt = 0.0;
        $loop = [
            'lag_ms' => 0.0,
            'lag_max_ms' => 0.0,
            'readers' => 0,
            'writers' => 0,
            'timers' => 0,
            'deferred' => 0,
            'ticks' => 0,
        ];
        $http = [
            'status_counts' => [],
            'route_counts' => [],
            'latency_count' => 0,
            'latency_sum_ms' => 0.0,
            'latency_max_ms' => 0.0,
        ];

        foreach (glob(rtrim($dir, '/') . '/worker-*.json') ?: [] as $file) {
            $json = @file_get_contents($file);
            $stats = is_string($json) && $json !== '' ? json_decode($json, true) : null;
            $seenAt = (float) ($stats['updated_at'] ?? 0);
            $pid = (int) ($stats['pid'] ?? 0);
            if (!is_array($stats) || $seenAt <= 0 || ($now - $seenAt) > 15 || !self::isLiveProcess($pid)) {
                @unlink($file);
                continue;
            }

            $workers[] = $stats;
            $updatedAt = max($updatedAt, $seenAt);
            $uptime = max($uptime, (float) ($stats['uptime'] ?? 0));
            $totalRequests += (int) ($stats['total_requests'] ?? 0);
            $totalConnections += (int) ($stats['total_connections'] ?? 0);
            $activeRequests += (int) ($stats['active_requests'] ?? 0);
            $activeConnections += (int) ($stats['active_connections'] ?? 0);
            $memoryCurrent += (int) ($stats['memory']['current'] ?? 0);
            $memoryPeak += (int) ($stats['memory']['peak'] ?? 0);
            $workerLoop = $stats['loop'] ?? [];
            $loop['lag_ms'] = max($loop['lag_ms'], (float) ($workerLoop['lag_ms'] ?? 0));
            $loop['lag_max_ms'] = max($loop['lag_max_ms'], (float) ($workerLoop['lag_max_ms'] ?? 0));
            foreach (['readers', 'writers', 'timers', 'deferred', 'ticks'] as $key) {
                $loop[$key] += (int) ($workerLoop[$key] ?? 0);
            }
            $workerHttp = $stats['http'] ?? [];
            $http['status_counts'] = self::mergeCounts($http['status_counts'], $workerHttp['status_counts'] ?? []);
            $http['route_counts'] = self::mergeCounts($http['route_counts'], $workerHttp['route_counts'] ?? []);
            $http['latency_count'] += (int) ($workerHttp['latency_count'] ?? 0);
            $http['latency_sum_ms'] += (float) ($workerHttp['latency_sum_ms'] ?? 0);
            $http['latency_max_ms'] = max($http['latency_max_ms'], (float) ($workerHttp['latency_max_ms'] ?? 0));
        }

        usort($workers, fn(array $a, array $b) => ($a['worker_id'] ?? 0) <=> ($b['worker_id'] ?? 0));
        $workerCount = 0;
        foreach ($workers as $worker) {
            $workerCount = max($workerCount, (int) ($worker['worker_count'] ?? 0));
        }
        $port = self::portFromStatsDir($dir);

        return [
            'running' => $workers !== [],
            'stats_dir' => $dir,
            'port' => $port,
            'pid' => self::supervisorPid($workers),
            'pids' => array_values(array_map(fn(array $worker) => (int) ($worker['pid'] ?? 0), $workers)),
            'worker_count' => $workerCount,
            'workers_reporting' => count($workers),
            'updated_at' => $updatedAt,
            'uptime' => $uptime,
            'total_requests' => $totalRequests,
            'total_connections' => $totalConnections,
            'active_requests' => $activeRequests,
            'active_connections' => $activeConnections,
            'memory' => [
                'current' => $memoryCurrent,
                'peak' => $memoryPeak,
            ],
            'loop' => $loop,
            'http' => $http,
            'workers' => $workers,
        ];
    }

    private static function emptyServer(int $port = 0): array
    {
        return [
            'running' => false,
            'stats_dir' => $port > 0 ? sys_get_temp_dir() . '/nexphant-http-' . $port : null,
            'port' => $port ?: null,
            'pid' => null,
            'pids' => [],
            'worker_count' => 0,
            'workers_reporting' => 0,
            'updated_at' => null,
            'uptime' => 0,
            'total_requests' => 0,
            'total_connections' => 0,
            'active_requests' => 0,
            'active_connections' => 0,
            'memory' => ['current' => 0, 'peak' => 0],
            'loop' => ['lag_ms' => 0, 'lag_max_ms' => 0, 'readers' => 0, 'writers' => 0, 'timers' => 0, 'deferred' => 0, 'ticks' => 0],
            'http' => ['status_counts' => [], 'route_counts' => [], 'latency_count' => 0, 'latency_sum_ms' => 0, 'latency_max_ms' => 0],
            'workers' => [],
        ];
    }

    private static function mergeCounts(array $base, array $next): array
    {
        foreach ($next as $key => $value) {
            $base[(string) $key] = (int) ($base[(string) $key] ?? 0) + (int) $value;
        }
        return $base;
    }

    private static function portFromStatsDir(string $dir): ?int
    {
        return preg_match('/nexphant-http-(\d+)$/', $dir, $m) ? (int) $m[1] : null;
    }

    private static function supervisorPid(array $workers): ?int
    {
        $parents = [];
        foreach ($workers as $worker) {
            $pid = (int) ($worker['pid'] ?? 0);
            if ($pid <= 0 || $pid !== (int) $pid || !is_readable("/proc/{$pid}/status")) {
                continue;
            }
            $status = @file_get_contents("/proc/{$pid}/status");
            if (is_string($status) && preg_match('/^PPid:\s+(\d+)/m', $status, $m)) {
                $parents[] = (int) $m[1];
            }
        }
        $counts = array_count_values($parents);
        arsort($counts);
        $pid = (int) (array_key_first($counts) ?? 0);
        return self::isLiveProcess($pid) ? $pid : null;
    }

    private static function isLiveProcess(int $pid): bool
    {
        if ($pid <= 1 || $pid !== (int) $pid || !function_exists('posix_kill') || !@posix_kill($pid, 0)) {
            return false;
        }
        $statusFile = "/proc/{$pid}/status";
        if (is_readable($statusFile)) {
            $status = @file_get_contents($statusFile);
            if (is_string($status) && preg_match('/^State:\s+Z/m', $status)) {
                return false;
            }
        }
        return true;
    }

    private static function load(): array
    {
        return function_exists('sys_getloadavg') ? (sys_getloadavg() ?: []) : [];
    }

    private static function activeFibers(): int
    {
        return class_exists('\Fiber') && method_exists(Runtime::class, 'stats') ? (Runtime::stats()['active_fibers'] ?? 0) : 0;
    }

    private static function activeTimers(): int
    {
        return method_exists(Runtime::class, 'stats') ? (Runtime::stats()['active_timers'] ?? 0) : 0;
    }
}
