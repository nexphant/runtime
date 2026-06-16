<?php

namespace nexphant\Runtime\Metrics;

class WorkerMetrics
{
    private static array $metrics = [];

    public static function init(int $workerId): void
    {
        self::$metrics[$workerId] = [
            'worker_id' => $workerId,
            'request_count' => 0,
            'active_connections' => 0,
            'active_requests' => 0,
            'total_response_time' => 0.0,
            'peak_response_time' => 0.0,
        ];
    }

    public static function recordRequest(int $workerId, float $responseTime): void
    {
        if (!isset(self::$metrics[$workerId])) {
            self::init($workerId);
        }

        self::$metrics[$workerId]['request_count']++;
        self::$metrics[$workerId]['total_response_time'] += $responseTime;
        
        if ($responseTime > self::$metrics[$workerId]['peak_response_time']) {
            self::$metrics[$workerId]['peak_response_time'] = $responseTime;
        }
    }

    public static function incrementActive(int $workerId): void
    {
        if (!isset(self::$metrics[$workerId])) {
            self::init($workerId);
        }
        self::$metrics[$workerId]['active_requests']++;
    }

    public static function decrementActive(int $workerId): void
    {
        if (isset(self::$metrics[$workerId])) {
            self::$metrics[$workerId]['active_requests']--;
        }
    }

    public static function setConnections(int $workerId, int $count): void
    {
        if (!isset(self::$metrics[$workerId])) {
            self::init($workerId);
        }
        self::$metrics[$workerId]['active_connections'] = $count;
    }

    public static function getMetrics(int $workerId): array
    {
        if (!isset(self::$metrics[$workerId])) {
            return [];
        }

        $m = self::$metrics[$workerId];
        $avgTime = $m['request_count'] > 0 
            ? $m['total_response_time'] / $m['request_count']
            : 0.0;

        return [
            'worker_id' => $m['worker_id'],
            'request_count' => $m['request_count'],
            'active_connections' => $m['active_connections'],
            'active_requests' => $m['active_requests'],
            'avg_response_time' => round($avgTime * 1000, 2),
            'peak_response_time' => round($m['peak_response_time'] * 1000, 2),
        ];
    }

    public static function getAllMetrics(): array
    {
        $all = [];
        foreach (self::$metrics as $workerId => $m) {
            $all[] = self::getMetrics($workerId);
        }
        return $all;
    }
}
