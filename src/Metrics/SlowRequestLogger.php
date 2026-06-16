<?php

namespace Nexphant\Runtime\Metrics;

class SlowRequestLogger
{
    private static float $threshold = 0.050;
    private static array $logs = [];
    private static int $maxLogs = 100;

    public static function setThreshold(float $seconds): void
    {
        self::$threshold = $seconds;
    }

    public static function log(array $request): void
    {
        if (count(self::$logs) >= self::$maxLogs) {
            array_shift(self::$logs);
        }

        self::$logs[] = [
            'timestamp' => microtime(true),
            'method' => $request['method'] ?? 'UNKNOWN',
            'path' => $request['path'] ?? '/',
            'response_time' => $request['response_time'] ?? 0.0,
            'worker_id' => $request['worker_id'] ?? 0,
        ];
    }

    public static function check(float $responseTime, array $request): void
    {
        if ($responseTime > self::$threshold) {
            self::log(array_merge($request, ['response_time' => $responseTime]));
        }
    }

    public static function getLogs(): array
    {
        return self::$logs;
    }

    public static function clear(): void
    {
        self::$logs = [];
    }
}
