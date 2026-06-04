<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Runtime\Observability;

use Nexph\Queue\QueueFactory;
use Nexph\Runtime\Runtime;

class RuntimeState {
    public static function snapshot(?string $driver = null): array {
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

        $runtimeAvailable = Runtime::available();
        $metrics = new RuntimeMetrics();
        $metrics->setGauge('queue_depth', (int)($queueStatus['depth'] ?? 0));
        $metrics->setGauge('active_workers', (int)($queueStatus['workers'] ?? 0));
        $metrics->setGauge('active_fibers', self::activeFibers());
        $metrics->setGauge('active_timers', self::activeTimers());
        $metrics->setGauge('dead_letter_count', $deadLetters);
        $metrics->setGauge('cpu_load_1m', self::load()[0] ?? 0);

        $data = $metrics->toArray();
        $data['runtime'] = [
            'mode' => $runtimeAvailable ? 'adaptive' : 'stateless',
            'available' => $runtimeAvailable,
            'capabilities' => Runtime::capabilities(),
            'degradation_state' => $runtimeAvailable ? 'none' : 'fallback_stateless',
        ];
        $data['queue'] = [
            'driver' => $driver,
            'running' => (bool)($queueStatus['running'] ?? false),
            'workers' => (int)($queueStatus['workers'] ?? 0),
            'depth' => (int)($queueStatus['depth'] ?? 0),
            'dead_letters' => $deadLetters,
            'error' => $queueError,
            'metrics' => $queueStatus['metrics'] ?? [],
        ];
        $data['system'] = [
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'memory_limit' => ini_get('memory_limit'),
            'cpu_load' => self::load(),
            'php_sapi' => PHP_SAPI,
        ];
        return $data;
    }

    private static function load(): array {
        return function_exists('sys_getloadavg') ? (sys_getloadavg() ?: []) : [];
    }

    private static function activeFibers(): int {
        return class_exists('\Fiber') && method_exists(Runtime::class, 'stats') ? (Runtime::stats()['active_fibers'] ?? 0) : 0;
    }

    private static function activeTimers(): int {
        return method_exists(Runtime::class, 'stats') ? (Runtime::stats()['active_timers'] ?? 0) : 0;
    }
}
