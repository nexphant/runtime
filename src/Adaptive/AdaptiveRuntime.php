<?php

namespace Nexphant\Runtime\Adaptive;

/**
 * Runtime facade — wires all adaptive primitives for a single worker.
 *
 * Usage in hot path:
 *   $adaptive = AdaptiveRuntime::get();
 *   $adaptive->stats->tickStart();
 *   // ... tick work ...
 *   $adaptive->stats->tickEnd();
 *   $limit = $adaptive->acceptLimit();
 *   $adaptive->publishToSharedTable();
 */
final class AdaptiveRuntime
{
    public WorkerLocalStats $stats;
    public AdaptiveAcceptController $accept;
    public LoopFairnessGuard $fairness;
    private RuntimePressureScore $scorer;
    private ?SharedWorkerTable $sharedTable;
    private bool $acceptThrottlingEnabled;

    private static array $instances = [];

    public function __construct(
        int $workerId,
        array $config = [],
        ?SharedWorkerTable $sharedTable = null
    ) {
        $this->stats = new WorkerLocalStats($workerId);

        $this->scorer = new RuntimePressureScore(
            $config['max_connections']     ?? 500,
            $config['max_requests']        ?? 1000,
            $config['max_pending_writes']  ?? 200,
            $config['max_tick_ms']         ?? 50.0
        );

        $this->accept = new AdaptiveAcceptController(
            0,
            $config['max_accept_per_tick'] ?? 16,
            $this->scorer
        );

        $this->fairness = new LoopFairnessGuard(
            $config['max_reads_per_connection_tick']  ?? 8,
            $config['max_writes_per_connection_tick'] ?? 8
        );

        $this->acceptThrottlingEnabled = $config['adaptive_accept_enabled'] ?? false;
        $this->sharedTable = $sharedTable;
    }

    /**
     * Returns allowed accepts for this tick and refreshes pressure score.
     */
    public function acceptLimit(): int
    {
        return $this->accept->acceptLimit($this->stats);
    }

    /**
     * Push local stats to shared memory for cross-worker visibility.
     */
    public function publishToSharedTable(): void
    {
        if ($this->sharedTable !== null) {
            $this->sharedTable->update($this->stats->workerId, $this->stats);
        }
    }

    /**
     * Returns current pressure score.
     * Reuses cached value if acceptLimit() was already called this tick.
     */
    public function pressure(): float
    {
        if ($this->stats->pressureCalculatedThisTick) {
            return $this->stats->runtimePressureScore;
        }
        return $this->scorer->calculate($this->stats);
    }

    public function isAcceptThrottlingEnabled(): bool
    {
        return $this->acceptThrottlingEnabled;
    }

    public static function get(int $workerId = 1): self
    {
        return self::$instances[$workerId] ?? throw new \LogicException(
            "AdaptiveRuntime not initialized for worker $workerId"
        );
    }

    public static function init(int $workerId, array $config = [], ?SharedWorkerTable $sharedTable = null): self
    {
        self::$instances[$workerId] = new self($workerId, $config, $sharedTable);
        return self::$instances[$workerId];
    }

    public static function hasInstance(int $workerId): bool
    {
        return isset(self::$instances[$workerId]);
    }
}
