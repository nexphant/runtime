<?php

declare(strict_types=1);

namespace Nexphant\Runtime\RateLimit;

final class TokenBucketLimiter implements RateLimiter
{
    private array $buckets = [];
    private int $capacity;
    private float $refillRate;
    private float $refillInterval;

    public function __construct(int $capacity, float $refillRate, float $refillInterval = 1.0)
    {
        $this->capacity = $capacity;
        $this->refillRate = $refillRate;
        $this->refillInterval = $refillInterval;
    }

    public function attempt(string $key, int $cost = 1): bool
    {
        $this->refill($key);
        
        $bucket = $this->buckets[$key] ?? null;
        if ($bucket === null) {
            $this->buckets[$key] = [
                'tokens' => (float)$this->capacity - $cost,
                'last_refill' => microtime(true),
            ];
            return true;
        }

        if ($bucket['tokens'] >= $cost) {
            $this->buckets[$key]['tokens'] -= $cost;
            return true;
        }

        return false;
    }

    public function remaining(string $key): int
    {
        $this->refill($key);
        $bucket = $this->buckets[$key] ?? null;
        return $bucket ? (int)floor($bucket['tokens']) : $this->capacity;
    }

    public function reset(string $key): void
    {
        unset($this->buckets[$key]);
    }

    public function resetAll(): void
    {
        $this->buckets = [];
    }

    private function refill(string $key): void
    {
        $bucket = $this->buckets[$key] ?? null;
        if ($bucket === null) {
            return;
        }

        $now = microtime(true);
        $elapsed = $now - $bucket['last_refill'];
        $intervals = floor($elapsed / $this->refillInterval);

        if ($intervals > 0) {
            $tokensToAdd = $intervals * $this->refillRate;
            $this->buckets[$key]['tokens'] = min($this->capacity, $bucket['tokens'] + $tokensToAdd);
            $this->buckets[$key]['last_refill'] = $now;
        }
    }
}
