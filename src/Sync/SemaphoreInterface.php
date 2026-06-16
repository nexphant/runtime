<?php

namespace nexphant\Runtime\Sync;

interface SemaphoreInterface
{
    public function acquire(float $timeout = 0): bool;
    public function release(): bool;
    public function isAcquired(): bool;
}
