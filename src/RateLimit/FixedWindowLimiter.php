<?php

declare(strict_types=1);

namespace nexphant\Runtime\RateLimit;

final class FixedWindowLimiter implements RateLimiter
{
    private array $windows = [];
    private int $limit;
    private float $windowSize;

    public function __construct(int $limit, float $windowSize = 60.0)
    {
        $this->limit = $limit;
        $this->windowSize = $windowSize;
    }

    public function attempt(string $key, int $cost = 1): bool
    {
        $this->cleanup($key);
        
        $window = $this->windows[$key] ?? null;
        $now = microtime(true);
        $windowStart = floor($now / $this->windowSize) * $this->windowSize;

        if ($window === null || $window['start'] !== $windowStart) {
            $this->windows[$key] = [
                'start' => $windowStart,
                'count' => $cost,
            ];
            return true;
        }

        if ($window['count'] + $cost <= $this->limit) {
            $this->windows[$key]['count'] += $cost;
            return true;
        }

        return false;
    }

    public function remaining(string $key): int
    {
        $this->cleanup($key);
        $window = $this->windows[$key] ?? null;
        return $window ? max(0, $this->limit - $window['count']) : $this->limit;
    }

    public function reset(string $key): void
    {
        unset($this->windows[$key]);
    }

    public function resetAll(): void
    {
        $this->windows = [];
    }

    private function cleanup(string $key): void
    {
        $window = $this->windows[$key] ?? null;
        if ($window === null) {
            return;
        }

        $now = microtime(true);
        $windowStart = floor($now / $this->windowSize) * $this->windowSize;

        if ($window['start'] < $windowStart) {
            unset($this->windows[$key]);
        }
    }
}
