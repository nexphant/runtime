<?php

namespace nexphant\Runtime\Adaptive;

/**
 * Per-tick fairness counters for connection read/write operations.
 * Prevents a single connection monopolizing the event loop.
 */
final class LoopFairnessGuard
{
    private int $maxReadsPerConnection;
    private int $maxWritesPerConnection;

    /** @var array<int, int> connection_id → reads this tick */
    private array $readCounts = [];
    /** @var array<int, int> connection_id → writes this tick */
    private array $writeCounts = [];

    public function __construct(
        int $maxReadsPerConnection  = 8,
        int $maxWritesPerConnection = 8
    ) {
        $this->maxReadsPerConnection  = $maxReadsPerConnection;
        $this->maxWritesPerConnection = $maxWritesPerConnection;
    }

    public function canRead(int $connId): bool
    {
        return ($this->readCounts[$connId] ?? 0) < $this->maxReadsPerConnection;
    }

    public function recordRead(int $connId): void
    {
        $this->readCounts[$connId] = ($this->readCounts[$connId] ?? 0) + 1;
    }

    public function canWrite(int $connId): bool
    {
        return ($this->writeCounts[$connId] ?? 0) < $this->maxWritesPerConnection;
    }

    public function recordWrite(int $connId): void
    {
        $this->writeCounts[$connId] = ($this->writeCounts[$connId] ?? 0) + 1;
    }

    /**
     * Reset at the start of each event loop tick.
     */
    public function reset(): void
    {
        $this->readCounts  = [];
        $this->writeCounts = [];
    }

    public function setLimits(int $maxReads, int $maxWrites): void
    {
        $this->maxReadsPerConnection  = max(1, $maxReads);
        $this->maxWritesPerConnection = max(1, $maxWrites);
    }
}
