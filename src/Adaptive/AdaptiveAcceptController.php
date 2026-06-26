<?php

namespace Nexphant\Runtime\Adaptive;

/**
 * Dynamically adjusts max accepts per tick based on runtime pressure.
 */
final class AdaptiveAcceptController
{
    private int $minAccept;
    private int $maxAccept;
    private RuntimePressureScore $scorer;

    public function __construct(
        int $minAccept = 0,
        int $maxAccept = 16,
        ?RuntimePressureScore $scorer = null
    ) {
        $this->minAccept = $minAccept;
        $this->maxAccept = $maxAccept;
        $this->scorer    = $scorer ?? new RuntimePressureScore();
    }

    /**
     * Compute accepts allowed for this tick based on current pressure.
     * Stores calculated pressure in stats so callers can read it without recalculating.
     */
    public function acceptLimit(WorkerLocalStats $stats): int
    {
        $pressure = $this->scorer->calculate($stats);
        $stats->runtimePressureScore = $pressure;
        $stats->pressureCalculatedThisTick = true;

        if ($pressure >= RuntimePressureScore::BUSY) {
            $stats->acceptPaused = false;
            return max(1, intdiv($this->maxAccept, 4));
        }

        $stats->acceptPaused = false;

        if ($pressure >= RuntimePressureScore::HEALTHY) {
            return max($this->minAccept, intdiv($this->maxAccept, 2));
        }

        if ($pressure >= RuntimePressureScore::IDLE) {
            $ratio = 1.0 - (($pressure - RuntimePressureScore::IDLE)
                          / (RuntimePressureScore::HEALTHY - RuntimePressureScore::IDLE));
            return max($this->minAccept, (int) round($ratio * $this->maxAccept));
        }

        return $this->maxAccept;
    }

    public function setMaxAccept(int $max): void
    {
        $this->maxAccept = max(1, $max);
    }
}
