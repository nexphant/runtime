<?php

declare(strict_types=1);

namespace nexphant\Runtime\Backpressure;

use nexphant\Core\Cancellation\CancellationToken;
use nexphant\Core\Cancellation\CancelledException;
use nexphant\Core\Cancellation\Deadline;
use nexphant\Core\Cancellation\DeadlineExceededException;
use nexphant\Core\Ownership\OwnerId;

/**
 * Counting semaphore for resource limiting
 */
final class Semaphore
{
    private int $permits;
    private int $available;
    private array $waitQueue = [];
    private array $heldBy = [];

    public function __construct(int $permits)
    {
        if ($permits <= 0) {
            throw new \InvalidArgumentException('Permits must be positive');
        }
        $this->permits = $permits;
        $this->available = $permits;
    }

    public function acquire(
        ?float $timeout = null,
        ?CancellationToken $token = null
    ): bool {
        $token?->throwIfCancelled();

        if ($this->available > 0) {
            $this->available--;
            $this->trackHolder();
            return true;
        }

        if ($timeout === null) {
            return false;
        }

        $deadline = microtime(true) + $timeout;
        $waitId = uniqid('wait_', true);
        $this->waitQueue[$waitId] = true;

        while (microtime(true) < $deadline) {
            $token?->throwIfCancelled();

            if ($this->available > 0) {
                unset($this->waitQueue[$waitId]);
                $this->available--;
                $this->trackHolder();
                return true;
            }

            usleep(1_000); // 1ms
        }

        unset($this->waitQueue[$waitId]);
        return false;
    }

    public function tryAcquire(): bool
    {
        if ($this->available > 0) {
            $this->available--;
            $this->trackHolder();
            return true;
        }
        return false;
    }

    public function release(): void
    {
        if ($this->available >= $this->permits) {
            return;
        }

        $this->available++;
        $this->untrackHolder();
    }

    public function releaseByOwner(OwnerId|string $owner): void
    {
        $ownerId = $owner instanceof OwnerId ? $owner->toString() : $owner;
        
        if (isset($this->heldBy[$ownerId])) {
            $count = $this->heldBy[$ownerId];
            unset($this->heldBy[$ownerId]);
            $this->available = min($this->permits, $this->available + $count);
        }
    }

    public function available(): int
    {
        return $this->available;
    }

    public function waiting(): int
    {
        return count($this->waitQueue);
    }

    private function trackHolder(): void
    {
        // Track by fiber/coroutine context if available
        $holder = \Fiber::getCurrent() ? spl_object_id(\Fiber::getCurrent()) : 'main';
        $this->heldBy[$holder] = ($this->heldBy[$holder] ?? 0) + 1;
    }

    private function untrackHolder(): void
    {
        $holder = \Fiber::getCurrent() ? spl_object_id(\Fiber::getCurrent()) : 'main';
        if (isset($this->heldBy[$holder])) {
            $this->heldBy[$holder]--;
            if ($this->heldBy[$holder] <= 0) {
                unset($this->heldBy[$holder]);
            }
        }
    }
}
