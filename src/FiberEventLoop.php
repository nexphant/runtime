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
use Nexphant\Core\Context\ContextStore;

/**
 * Lightweight cooperative event loop.
 * 
 * Manages coroutine scheduling, timers, and I/O events.
 * Uses min-heap for timers instead of scanning all timers each tick.
 */
class FiberEventLoop
{
    private array $ready = [];
    private array $sleeping = [];
    private array $timers = [];
    private \SplPriorityQueue $timerHeap;
    private bool $running = false;
    private int $nextTimerId = 1;
    /** @var resource[] */
    private array $readStreams = [];
    private array $readCallbacks = [];
    /** @var resource[] */
    private array $writeStreams = [];
    private array $writeCallbacks = [];

    public function __construct()
    {
        $this->timerHeap = new \SplPriorityQueue();
        $this->timerHeap->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
    }

    /**
     * Schedule a coroutine for execution.
     */
    public function schedule(FiberCoroutine $coroutine): void
    {
        $this->ready[] = $coroutine;
    }

    /**
     * Schedule fiber to wake after delay.
     */
    public function sleepFiber(Fiber $fiber, float $seconds): void
    {
        $this->sleeping[] = ['fiber' => $fiber, 'wake' => microtime(true) + $seconds];
    }

    /**
     * Schedule a timer callback.
     */
    public function timer(float $seconds, callable $callback, bool $repeat = false): int
    {
        // Capture current context
        $context = ContextStore::instance()->current();
        $parentOwnerId = $context->ownerId();

        // Create timer owner
        $timerOwner = class_exists('\Nexphant\Runtime\Runtime') && \Nexphant\Runtime\Runtime::available()
            ? \Nexphant\Runtime\Runtime::owners()->open(
                \Nexphant\Core\Ownership\OwnerType::TIMER,
                $parentOwnerId ? \Nexphant\Runtime\Runtime::owners()->get($parentOwnerId)?->id() : null,
                ['interval' => $seconds, 'repeat' => $repeat]
            )
            : null;

        $id = $this->nextTimerId++;
        $next = microtime(true) + $seconds;
        $this->timers[$id] = [
            'callback' => $callback,
            'interval' => $seconds,
            'next' => $next,
            'repeat' => $repeat,
            'context' => $context,
            'owner' => $timerOwner,
        ];
        $this->timerHeap->insert($id, -$next);

        // Track timer as resource
        if (class_exists('\Nexphant\Core\Resource\ResourceRegistry') && $timerOwner && $timerOwner->id()) {
            \Nexphant\Core\Resource\ResourceRegistry::instance()->track(
                (object) ['timer_id' => $id],
                'timer',
                $timerOwner->id()
            );
        }

        return $id;
    }

    /**
     * Cancel a timer.
     */
    public function cancelTimer(int $id): void
    {
        if (isset($this->timers[$id])) {
            $timer = $this->timers[$id];
            if ($timer['owner'] ?? null) {
                $timer['owner']->close('timer_cancelled');
            }
        }
        unset($this->timers[$id]);
    }

    /**
     * Register a stream for read readiness.
     */
    public function onReadable($stream, callable $callback): void
    {
        $id = (int) $stream;
        $this->readStreams[$id] = $stream;
        $this->readCallbacks[$id] = $callback;
    }

    /**
     * Remove read watcher.
     */
    public function removeReadable($stream): void
    {
        $id = (int) $stream;
        unset($this->readStreams[$id], $this->readCallbacks[$id]);
    }

    /**
     * Register a stream for write readiness.
     */
    public function onWritable($stream, callable $callback): void
    {
        $id = (int) $stream;
        $this->writeStreams[$id] = $stream;
        $this->writeCallbacks[$id] = $callback;
    }

    /**
     * Remove write watcher.
     */
    public function removeWritable($stream): void
    {
        $id = (int) $stream;
        unset($this->writeStreams[$id], $this->writeCallbacks[$id]);
    }

    /**
     * Run event loop until all work complete.
     */
    public function run(): void
    {
        if ($this->running) {
            throw new \RuntimeException('Event loop is already running');
        }

        $this->running = true;

        while ($this->running && $this->hasWork()) {
            $this->tick();
        }

        $this->running = false;
    }

    /**
     * Stop event loop.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Check if event loop is running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Single event loop iteration.
     */
    private function tick(): void
    {
        $now = microtime(true);

        // Process timers via heap — O(log n) per expired timer
        $this->processTimers($now);

        // Wake sleeping fibers
        $this->processSleeping($now);

        // Poll I/O with stream_select
        $this->pollIO();

        // Execute ready coroutines
        $ready = $this->ready;
        $this->ready = [];
        if (count($ready) > 50000) {
            $ready = array_slice($ready, -50000);
        }

        foreach ($ready as $coroutine) {
            if ($coroutine->isFinished()) {
                continue;
            }

            try {
                // Restore context before resuming fiber
                $fiber = $coroutine->fiber();
                if ($fiber !== null) {
                    $store = ContextStore::instance();
                    $currentContext = $store->current();
                    $coroutine->resume();
                    // Context is automatically restored via WeakMap per-fiber storage
                } else {
                    $coroutine->resume();
                }

                if (!$coroutine->isFinished() && $coroutine->lastSuspend() === FiberCoroutine::SUSPEND_YIELD) {
                    $this->ready[] = $coroutine;
                }
            } catch (\Throwable $e) {
                error_log("Coroutine error: " . $e->getMessage());

                if (class_exists('\Nexphant\Core\Context\ContextStore')) {
                    $ctx = \Nexphant\Core\Context\ContextStore::instance()->current();
                    $ownerId = $ctx->ownerId();
                    if ($ownerId && class_exists('\Nexphant\Runtime\Runtime')) {
                        $owner = \Nexphant\Runtime\Runtime::owners()->get($ownerId);
                        if ($owner && $owner->isAlive()) {
                            $owner->close('fiber_error');
                        }
                    }
                }
            }
        }

        if (empty($this->ready) && empty($this->readStreams) && empty($this->writeStreams)) {
            $nextWake = $this->nextWakeTime();
            $sleepTime = $nextWake !== null ? max(0, $nextWake - microtime(true)) : 0;
            if ($sleepTime > 0) {
                usleep((int) min($sleepTime * 1_000_000, 10_000));
            }
        }
    }

    private function processTimers(float $now): void
    {
        while (!$this->timerHeap->isEmpty()) {
            $id = $this->timerHeap->top();

            // Cancelled timer — skip
            if (!isset($this->timers[$id])) {
                $this->timerHeap->extract();
                continue;
            }

            $timer = $this->timers[$id];
            if ($timer['next'] > $now) {
                break; // All remaining timers are in the future
            }

            $this->timerHeap->extract();

            // Check drain state before executing timer
            if (class_exists('\Nexphant\Core\Drain\DrainController')) {
                $drainController = \Nexphant\Core\Drain\DrainController::instance();
                if (!$drainController->isAccepting()) {
                    // Skip timer execution during drain
                    if ($timer['owner'] ?? null) {
                        $timer['owner']->close('timer_skipped_drain');
                    }
                    unset($this->timers[$id]);
                    continue;
                }

                // Check cancellation token
                $token = $drainController->cancellationToken();
                if ($token && $token->isCancelled()) {
                    if ($timer['owner'] ?? null) {
                        $timer['owner']->close('timer_cancelled_drain');
                    }
                    unset($this->timers[$id]);
                    continue;
                }
            }

            // Restore context for timer callback
            $context = $timer['context'] ?? null;
            if ($context !== null) {
                $store = ContextStore::instance();
                $store->runWith($context, $timer['callback']);
            } else {
                ($timer['callback'])();
            }

            // Re-check if timer still exists (callback may cancel it)
            if (!isset($this->timers[$id])) {
                continue;
            }

            if ($timer['repeat']) {
                $next = $now + $timer['interval'];
                $this->timers[$id]['next'] = $next;
                $this->timerHeap->insert($id, -$next);
            } else {
                // Non-repeating timer completed, close owner
                if ($timer['owner'] ?? null) {
                    $timer['owner']->close('timer_completed');
                }
                unset($this->timers[$id]);
            }
        }
    }

    private function processSleeping(float $now): void
    {
        $stillSleeping = [];
        foreach ($this->sleeping as $item) {
            if ($item['wake'] <= $now) {
                if ($item['fiber']->isTerminated()) {
                    continue;
                }
                if ($item['fiber']->isSuspended()) {
                    $coroutine = new FiberCoroutine($item['fiber']);
                    $coroutine->markStarted();
                    $this->ready[] = $coroutine;
                }
            } else {
                $stillSleeping[] = $item;
            }
        }
        $this->sleeping = $stillSleeping;
        if (count($this->sleeping) > 100000) {
            $this->sleeping = array_slice($this->sleeping, -50000);
        }
    }

    private function pollIO(): void
    {
        if (empty($this->readStreams) && empty($this->writeStreams)) {
            return;
        }

        $read = array_values($this->readStreams);
        $write = array_values($this->writeStreams);
        $except = null;

        // Non-blocking poll if we have ready coroutines, otherwise short block
        $timeout = empty($this->ready) ? 10000 : 0; // microseconds
        $tvSec = 0;
        $tvUsec = $timeout;

        if (empty($read) && empty($write)) {
            return;
        }

        $result = @stream_select($read, $write, $except, $tvSec, $tvUsec);
        if ($result === false || $result === 0) {
            return;
        }

        foreach ($read as $stream) {
            $id = (int) $stream;
            if (isset($this->readCallbacks[$id])) {
                ($this->readCallbacks[$id])($stream);
            }
        }

        foreach ($write as $stream) {
            $id = (int) $stream;
            if (isset($this->writeCallbacks[$id])) {
                ($this->writeCallbacks[$id])($stream);
            }
        }
    }

    private function nextWakeTime(): ?float
    {
        $next = null;

        foreach ($this->sleeping as $item) {
            $next = $next === null ? $item['wake'] : min($next, $item['wake']);
        }

        // Check heap top
        while (!$this->timerHeap->isEmpty()) {
            $id = $this->timerHeap->top();
            if (!isset($this->timers[$id])) {
                $this->timerHeap->extract();
                continue;
            }
            $timerNext = $this->timers[$id]['next'];
            $next = $next === null ? $timerNext : min($next, $timerNext);
            break;
        }

        return $next;
    }

    /**
     * Check if loop has pending work.
     */
    private function hasWork(): bool
    {
        return !empty($this->ready)
            || !empty($this->sleeping)
            || !empty($this->timers)
            || !empty($this->readStreams)
            || !empty($this->writeStreams);
    }
}
