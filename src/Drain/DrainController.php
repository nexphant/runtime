<?php

declare(strict_types=1);

namespace Nexph\Runtime\Drain;

use Nexph\Core\Ownership\OwnerId;
use Nexph\Core\Cancellation\CancellationSource;

/**
 * Controller for graceful shutdown and draining
 */
final class DrainController
{
    private static ?self $instance = null;
    private string $state = 'accepting';
    private array $inFlight = [];
    private float $drainStartedAt = 0;
    private ?CancellationSource $cancellationSource = null;

    private function __construct()
    {
        $this->cancellationSource = new CancellationSource();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function stopAccepting(): void
    {
        if ($this->state === 'accepting') {
            $this->state = 'draining';
            $this->drainStartedAt = microtime(true);
        }
    }

    public function trackInFlight(OwnerId|string $owner): void
    {
        $ownerId = $owner instanceof OwnerId ? $owner->toString() : $owner;
        $this->inFlight[$ownerId] = microtime(true);
    }

    public function finishInFlight(OwnerId|string $owner): void
    {
        $ownerId = $owner instanceof OwnerId ? $owner->toString() : $owner;
        unset($this->inFlight[$ownerId]);
    }

    public function waitInFlight(float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;

        while (!empty($this->inFlight)) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(10_000); // 10ms
        }

        return true;
    }

    public function forceStop(string $reason = ''): void
    {
        $this->state = 'forced';
        $this->cancellationSource?->cancel($reason ?: 'drain_timeout');
        $this->inFlight = [];
    }

    public function cancellationToken()
    {
        return $this->cancellationSource?->token();
    }

    public function state(): string
    {
        return $this->state;
    }

    public function isAccepting(): bool
    {
        return $this->state === 'accepting';
    }

    public function isDraining(): bool
    {
        return $this->state === 'draining';
    }

    public function isStopped(): bool
    {
        return $this->state === 'stopped' || $this->state === 'forced';
    }

    public function stats(): array
    {
        $now = microtime(true);
        $drainDuration = $this->drainStartedAt > 0 
            ? $now - $this->drainStartedAt 
            : 0;

        return [
            'state' => $this->state,
            'in_flight_count' => count($this->inFlight),
            'drain_duration' => $drainDuration,
        ];
    }
}
