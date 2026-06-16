<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Runtime;

use Fiber;

/**
 * Coroutine wrapper around PHP Fiber.
 * 
 * Provides explicit lifecycle management and state tracking.
 */
class FiberCoroutine
{
    public const SUSPEND_YIELD = 'yield';
    public const SUSPEND_SLEEP = 'sleep';
    public const SUSPEND_CHANNEL = 'channel';

    private ?Fiber $fiber;
    private bool $started = false;
    private bool $finished = false;
    private mixed $result = null;
    private mixed $lastSuspend = null;
    private bool $synchronous;

    public function __construct(?Fiber $fiber, bool $synchronous = false)
    {
        $this->fiber = $fiber;
        $this->synchronous = $synchronous;

        if ($synchronous) {
            $this->finished = true;
        }
    }

    /**
     * Resume coroutine execution.
     */
    public function resume(mixed $value = null): mixed
    {
        if ($this->synchronous || $this->finished || $this->fiber === null) {
            return $this->result;
        }

        if (!$this->started) {
            $this->started = true;
            $this->result = $this->fiber->start();
        } else if ($this->fiber->isSuspended()) {
            $this->result = $this->fiber->resume($value);
        }

        $this->lastSuspend = $this->fiber->isSuspended() ? $this->result : null;

        if ($this->fiber->isTerminated()) {
            $this->finished = true;
            $this->result = $this->fiber->getReturn();
            $this->lastSuspend = null;
        }

        return $this->result;
    }

    /**
     * Mark coroutine as already started (for resumed fibers).
     */
    public function markStarted(): void
    {
        $this->started = true;
    }

    /**
     * Check if coroutine is finished.
     */
    public function isFinished(): bool
    {
        if (!$this->finished && $this->fiber !== null && $this->fiber->isTerminated()) {
            $this->finished = true;
            $this->result = $this->fiber->getReturn();
            $this->lastSuspend = null;
        }

        return $this->finished;
    }

    public function lastSuspend(): mixed
    {
        return $this->lastSuspend;
    }

    /**
     * Get coroutine result (blocks until finished).
     */
    public function await(): mixed
    {
        if ($this->synchronous) {
            return $this->result;
        }

        if (Runtime::available() && !Runtime::isRunning()) {
            Runtime::run();
            $this->isFinished();
            return $this->result;
        }

        while (!$this->isFinished()) {
            $this->resume();
        }

        return $this->result;
    }

    /**
     * Get underlying fiber.
     */
    public function fiber(): ?Fiber
    {
        return $this->fiber;
    }
}
