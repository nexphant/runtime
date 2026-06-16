<?php

declare(strict_types=1);

namespace nexphant\Runtime\RateLimit;

interface RateLimiter
{
    public function attempt(string $key, int $cost = 1): bool;
    public function remaining(string $key): int;
    public function reset(string $key): void;
    public function resetAll(): void;
}
