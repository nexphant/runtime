<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace nexphant\Runtime;

/**
 * Timer management for event loop.
 * 
 * Supports one-shot and repeating timers with microsecond precision.
 */
class Timer
{
    /**
     * Schedule callback after delay.
     */
    public static function after(float $seconds, callable $callback): int
    {
        if (!Runtime::available()) {
            return 0;
        }
        return Runtime::loop()->timer($seconds, $callback, false);
    }

    /**
     * Schedule repeating callback.
     */
    public static function every(float $seconds, callable $callback): int
    {
        if (!Runtime::available()) {
            return 0;
        }
        return Runtime::loop()->timer($seconds, $callback, true);
    }

    /**
     * Cancel timer.
     */
    public static function cancel(int $id): void
    {
        if (!Runtime::available()) {
            return;
        }
        Runtime::loop()->cancelTimer($id);
    }

    /**
     * Defer callback to next tick.
     */
    public static function defer(callable $callback): void
    {
        Runtime::spawn($callback);
    }
}
