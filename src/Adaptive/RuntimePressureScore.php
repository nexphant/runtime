<?php

namespace Nexphant\Runtime\Adaptive;

/**
 * Adaptive pressure score: 0.0 (idle) → 1.0 (overloaded).
 */
final class RuntimePressureScore
{
    // Thresholds
    public const IDLE       = 0.3;
    public const HEALTHY    = 0.6;
    public const BUSY       = 0.8;
    public const OVERLOADED = 1.0;

    private int $maxConnections;
    private int $maxRequests;
    private int $maxPendingWrites;
    private float $maxTickDurationMs;

    public function __construct(
        int $maxConnections    = 500,
        int $maxRequests       = 1000,
        int $maxPendingWrites  = 200,
        float $maxTickDurationMs = 50.0
    ) {
        $this->maxConnections    = $maxConnections;
        $this->maxRequests       = $maxRequests;
        $this->maxPendingWrites  = $maxPendingWrites;
        $this->maxTickDurationMs = $maxTickDurationMs;
    }

    public function calculate(WorkerLocalStats $stats): float
    {
        $connScore  = $this->ratio($stats->activeConnections, $this->maxConnections);
        $reqScore   = $this->ratio($stats->activeRequests,   $this->maxRequests);
        $writeScore = $this->ratio($stats->pendingWrites,    $this->maxPendingWrites);
        $tickScore  = $this->ratio($stats->loopTickDuration, $this->maxTickDurationMs);

        // Weighted: conn 30%, req 30%, writes 20%, tick 20%
        $score = ($connScore * 0.30)
               + ($reqScore  * 0.30)
               + ($writeScore * 0.20)
               + ($tickScore  * 0.20);

        return min(1.0, max(0.0, $score));
    }

    public function calculateFromValues(
        int $activeConnections,
        int $activeRequests,
        int $pendingWrites,
        float $loopTickDurationMs
    ): float {
        $s = new WorkerLocalStats(0);
        $s->activeConnections = $activeConnections;
        $s->activeRequests    = $activeRequests;
        $s->pendingWrites     = $pendingWrites;
        $s->loopTickDuration  = $loopTickDurationMs;
        return $this->calculate($s);
    }

    public static function label(float $score): string
    {
        if ($score < self::IDLE)    return 'idle';
        if ($score < self::HEALTHY) return 'healthy';
        if ($score < self::BUSY)    return 'busy';
        return 'overloaded';
    }

    private function ratio(int|float $value, int|float $max): float
    {
        if ($max <= 0) return 0.0;
        return min(1.0, $value / $max);
    }
}
