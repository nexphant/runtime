<?php

namespace Nexphant\Runtime\Adaptive;

/**
 * Lightweight per-worker runtime stats.
 * Integer counters only — zero object allocations in hot path.
 */
final class WorkerLocalStats
{
    public int $workerId = 0;
    public int $activeConnections = 0;
    public int $activeRequests = 0;
    public int $pendingWrites = 0;
    public float $loopTickDuration = 0.0;
    public bool $acceptPaused = false;
    public float $runtimePressureScore = 0.0;
    /** @internal Set by AdaptiveAcceptController to avoid double calculation per tick */
    public bool $pressureCalculatedThisTick = false;

    private float $tickStart = 0.0;

    public function __construct(int $workerId)
    {
        $this->workerId = $workerId;
    }

    public function tickStart(): void
    {
        $this->tickStart = hrtime(true);
    }

    public function tickEnd(): void
    {
        if ($this->tickStart > 0) {
            $this->loopTickDuration = (hrtime(true) - $this->tickStart) / 1e6; // ms
        }
    }

    public function toArray(): array
    {
        return [
            'worker_id'              => $this->workerId,
            'active_connections'     => $this->activeConnections,
            'active_requests'        => $this->activeRequests,
            'pending_writes'         => $this->pendingWrites,
            'loop_tick_duration_ms'  => $this->loopTickDuration,
            'accept_paused'          => $this->acceptPaused,
            'runtime_pressure_score' => $this->runtimePressureScore,
        ];
    }
}
