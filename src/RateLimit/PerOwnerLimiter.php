<?php

declare(strict_types=1);

namespace nexphant\Runtime\RateLimit;

use nexphant\Runtime\Runtime;

final class PerOwnerLimiter implements RateLimiter
{
    private RateLimiter $baseLimiter;

    public function __construct(RateLimiter $baseLimiter)
    {
        $this->baseLimiter = $baseLimiter;
    }

    public function attempt(string $key, int $cost = 1): bool
    {
        $scopedKey = $this->scopeKey($key);
        return $this->baseLimiter->attempt($scopedKey, $cost);
    }

    public function remaining(string $key): int
    {
        $scopedKey = $this->scopeKey($key);
        return $this->baseLimiter->remaining($scopedKey);
    }

    public function reset(string $key): void
    {
        $scopedKey = $this->scopeKey($key);
        $this->baseLimiter->reset($scopedKey);
    }

    public function resetAll(): void
    {
        $this->baseLimiter->resetAll();
    }

    private function scopeKey(string $key): string
    {
        if (class_exists('\\nexphant\\Runtime\\Runtime') && Runtime::available()) {
            $ownerId = Runtime::context()->ownerId();
            if ($ownerId) {
                return $ownerId->toString() . ':' . $key;
            }
        }
        return $key;
    }
}
