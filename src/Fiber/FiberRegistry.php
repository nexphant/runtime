<?php
declare(strict_types=1);

namespace Nexphant\Runtime\Fiber;

use Nexphant\Core\Ownership\OwnerId;
use Nexphant\Core\Context\RuntimeContext;

final class FiberRegistry
{
    private static ?self $instance = null;
    private array $fibers = [];

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function track(
        string $fiberId,
        ?OwnerId $ownerId,
        ?RuntimeContext $context
    ): void {
        $this->fibers[$fiberId] = [
            'id' => $fiberId,
            'owner_id' => $ownerId?->toString(),
            'context' => $context,
            'state' => 'created',
            'created_at' => microtime(true),
            'last_resume_at' => null,
        ];
    }

    public function markRunning(string $fiberId): void
    {
        if (isset($this->fibers[$fiberId])) {
            $this->fibers[$fiberId]['state'] = 'running';
            $this->fibers[$fiberId]['last_resume_at'] = microtime(true);
        }
    }

    public function markSuspended(string $fiberId): void
    {
        if (isset($this->fibers[$fiberId])) {
            $this->fibers[$fiberId]['state'] = 'suspended';
        }
    }

    public function markCompleted(string $fiberId): void
    {
        if (isset($this->fibers[$fiberId])) {
            $this->fibers[$fiberId]['state'] = 'completed';
            $this->cleanup($fiberId);
        }
    }

    public function markFailed(string $fiberId): void
    {
        if (isset($this->fibers[$fiberId])) {
            $this->fibers[$fiberId]['state'] = 'failed';
            $this->cleanup($fiberId);
        }
    }

    public function markCancelled(string $fiberId): void
    {
        if (isset($this->fibers[$fiberId])) {
            $this->fibers[$fiberId]['state'] = 'cancelled';
            $this->cleanup($fiberId);
        }
    }

    public function cleanup(string $fiberId): void
    {
        unset($this->fibers[$fiberId]);
    }

    public function stats(): array
    {
        $states = ['created' => 0, 'running' => 0, 'suspended' => 0, 'completed' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($this->fibers as $fiber) {
            $states[$fiber['state']]++;
        }
        return $states;
    }

    public function suspendedFibers(float $minAge = 5.0): array
    {
        $now = microtime(true);
        $result = [];
        foreach ($this->fibers as $fiber) {
            if ($fiber['state'] === 'suspended' && $fiber['last_resume_at']) {
                $age = $now - $fiber['last_resume_at'];
                if ($age >= $minAge) {
                    $result[] = [
                        'id' => $fiber['id'],
                        'owner_id' => $fiber['owner_id'],
                        'suspended_for_ms' => (int)($age * 1000),
                    ];
                }
            }
        }
        return $result;
    }
}
