<?php

declare(strict_types=1);

namespace Nexphant\Runtime\Backpressure;

use Nexphant\Core\Cancellation\CancellationToken;
use Nexphant\Core\Cancellation\CancelledException;
use Nexphant\Core\Cancellation\Deadline;
use Nexphant\Core\Cancellation\DeadlineExceededException;
use Nexphant\Core\Ownership\OwnerId;

/**
 * Counting semaphore for resource limiting
 */
final class Semaphore
{
    private int $permits;
    private int $available;
    private array $waitQueue = [];
    private \WeakMap $heldBy;

    public function __construct(int $permits)
    {
        if ($permits <= 0) {
            throw new \InvalidArgumentException('Permits must be positive');
        }
        $this->permits = $permits;
        $this->available = $permits;
        $this->heldBy = new \WeakMap();
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
        // WeakMap cleanup is automatic, no manual tracking needed
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
        $fiber = \Fiber::getCurrent();
        if ($fiber) {
            $this->heldBy[$fiber] = ($this->heldBy[$fiber] ?? 0) + 1;
        }
    }

    private function untrackHolder(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber && isset($this->heldBy[$fiber])) {
            $this->heldBy[$fiber]--;
            if ($this->heldBy[$fiber] <= 0) {
                unset($this->heldBy[$fiber]);
            }
        }
    }
}
