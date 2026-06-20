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
use Nexphant\Runtime\Backpressure\BoundedExecutor;
use Nexphant\Runtime\Backpressure\Semaphore;
use Nexphant\Core\Cancellation\CancellationSource;
use Nexphant\Core\Cancellation\CancelledException;
use Nexphant\Core\Cancellation\Deadline;
use Nexphant\Core\Cancellation\CancellationToken;
use Nexphant\Core\Context\ContextStore;
use Nexphant\Core\Context\RuntimeContext;
use Nexphant\Runtime\Metrics\RuntimeMetrics;
use Nexphant\Runtime\Observability\LeakSnapshot;
use Nexphant\Core\Ownership\OwnerRegistry;
use Nexphant\Core\Ownership\OwnerType;
use Nexphant\Core\Resource\ResourceRegistry;

/**
 * Adaptive stateful runtime layer for nexphant.
 * 
 * Enables async/stateful features only when environment supports them.
 * Falls back to synchronous execution on shared hosting/FPM.
 * 
 * Philosophy: "No magic in development, magic in runtime adaptability"
 */
class Runtime
{
    private static ?FiberEventLoop $loop = null;
    private static bool $initialized = false;
    private static array $capabilities = [];

    /**
     * Initialize runtime and detect capabilities.
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$capabilities = [
            'fibers' => class_exists('Fiber'),
            'pcntl' => extension_loaded('pcntl'),
            'sockets' => extension_loaded('sockets'),
            'posix' => extension_loaded('posix'),
            'redis' => extension_loaded('redis'),
            'cli' => PHP_SAPI === 'cli',
        ];

        self::$initialized = true;
    }

    public static function configure(array $config): void
    {
        self::init();
        JsonSerializer::configure($config);
        RuntimeCache::configure($config);
        ResponseCache::configure($config);
    }

    public static function json(mixed $data, int $flags = JsonSerializer::DEFAULT_FLAGS): string
    {
        return JsonSerializer::encode($data, $flags);
    }

    /**
     * Create cancellation source.
     */
    public static function cancel(): CancellationSource
    {
        return new CancellationSource();
    }

    /**
     * Create deadline from seconds.
     */
    public static function deadline(float $seconds): Deadline
    {
        return Deadline::fromSeconds($seconds);
    }

    /**
     * Create a semaphore with N permits.
     */
    public static function semaphore(int $permits): Semaphore
    {
        return new Semaphore($permits);
    }

    /**
     * Create a bounded executor.
     */
    public static function executor(
        int $maxConcurrency,
        int $maxQueueSize = 100,
        string $rejectPolicy = 'reject'
    ): BoundedExecutor {
        return new BoundedExecutor($maxConcurrency, $maxQueueSize, $rejectPolicy);
    }

    /**
     * Create a channel.
     */
    public static function channel(int $capacity = 0): Channel
    {
        return new Channel($capacity);
    }

    /**
     * Detached fiber with explicit owner
     */
    public static function detached(callable $fn, \Nexphant\Lifecycle\Owner $owner): void
    {
        if ($owner instanceof \Nexphant\Lifecycle\RequestOwner || $owner instanceof \Nexphant\Lifecycle\ChildFiberOwner) {
            throw new \InvalidArgumentException('Detached fiber requires explicit non-request owner');
        }

        if (!self::available()) {
            $childCtx = new \Nexphant\Lifecycle\ChildFiberOwner($owner instanceof \Nexphant\Lifecycle\OwnerScope ? $owner : null);
            $owner->child($childCtx);
            try {
                $fn($childCtx);
            } finally {
                $childCtx->close();
            }
            return;
        }

        $fiber = new \Fiber(function () use ($fn, $owner) {
            $childCtx = new \Nexphant\Lifecycle\ChildFiberOwner($owner instanceof \Nexphant\Lifecycle\OwnerScope ? $owner : null);
            $owner->child($childCtx);
            try {
                $fn($childCtx);
            } finally {
                $childCtx->close();
            }
        });

        $fiber->start();
    }

    /**
     * Get queue runtime
     */
    public static function queue(string $name = 'default'): \Nexphant\Queue\QueueRuntime
    {
        static $queues = [];
        if (!isset($queues[$name])) {
            $queues[$name] = new \Nexphant\Queue\QueueRuntime();
        }
        return $queues[$name];
    }

    /**
     * Get scheduler runtime
     */
    public static function schedule(): \Nexphant\Scheduler\SchedulerRuntime
    {
        static $scheduler = null;
        if ($scheduler === null) {
            $scheduler = new \Nexphant\Scheduler\SchedulerRuntime();
        }
        return $scheduler;
    }

    /**
     * Create a timer.
     */
    public static function timer(float $seconds, callable $callback, bool $repeat = false): int
    {
        if (!self::available()) {
            return 0;
        }
        return self::loop()->timer($seconds, $callback, $repeat);
    }

    /**
     * Get memory monitor.
     */
    public static function memoryMonitor(): MemoryMonitor
    {
        static $monitor = null;
        if ($monitor === null) {
            $monitor = new MemoryMonitor();
        }
        return $monitor;
    }

    /**
     * Get memory leak detector.
     */
    public static function leakDetector(): MemoryLeakDetector
    {
        static $detector = null;
        if ($detector === null) {
            $detector = new MemoryLeakDetector();
        }
        return $detector;
    }

    /**
     * Get current memory usage.
     */
    public static function memoryUsage(bool $realUsage = true): int
    {
        return memory_get_usage($realUsage);
    }

    /**
     * Get memory limit from ini.
     */
    public static function memoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return -1;
        }
        return self::parseMemoryString($limit);
    }

    /**
     * Get memory usage percentage.
     */
    public static function memoryPressure(): float
    {
        $limit = self::memoryLimit();
        if ($limit <= 0) {
            return 0.0;
        }
        return memory_get_usage(true) / $limit;
    }

    /**
     * Force garbage collection.
     */
    public static function gc(): int
    {
        $before = memory_get_usage(true);
        gc_collect_cycles();
        gc_mem_caches();
        $after = memory_get_usage(true);
        return $before - $after;
    }

    /**
     * Parse memory string to bytes.
     */
    private static function parseMemoryString(string $val): int
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $val = (int) $val;
        switch ($last) {
            case 'g': $val *= 1024;
            case 'm': $val *= 1024;
            case 'k': $val *= 1024;
        }
        return $val;
    }

    /**
     * Get runtime metrics snapshot.
     */
    public static function metrics(): RuntimeMetrics
    {
        return RuntimeMetrics::instance();
    }

    /**
     * Get leak snapshot.
     */
    public static function leaks(): LeakSnapshot
    {
        return new LeakSnapshot();
    }

    /**
     * Get resource registry.
     */
    public static function resources(): ResourceRegistry
    {
        return ResourceRegistry::instance();
    }

    /**
     * Check if runtime is available (CLI + Fibers).
     */
    public static function available(): bool
    {
        self::init();
        return self::$capabilities['fibers'] && self::$capabilities['cli'];
    }

    /**
     * Get runtime capabilities.
     */
    public static function capabilities(): array
    {
        self::init();
        return self::$capabilities;
    }

    /**
     * Get or create event loop.
     */
    public static function loop(): FiberEventLoop
    {
        if (!self::available()) {
            throw new \RuntimeException('Runtime not available. Requires CLI mode and Fiber support.');
        }

        if (self::$loop === null) {
            self::$loop = new FiberEventLoop();
        }

        return self::$loop;
    }

    /**
     * Check if event loop is running.
     */
    public static function isRunning(): bool
    {
        return self::$loop !== null && self::$loop->isRunning();
    }

    /**
     * Spawn a coroutine (Fiber-based).
     * Falls back to immediate execution if runtime unavailable.
     */
    public static function spawn(callable $fn, mixed ...$args): FiberCoroutine
    {
        if (!self::available()) {
            // Fallback: execute synchronously
            $fn(...$args);
            return new FiberCoroutine(null, true);
        }

        // Inherit context from caller
        $parentContext = ContextStore::instance()->current();
        $parentOwnerId = $parentContext->ownerId();

        // Create fiber owner
        $fiberOwner = $parentOwnerId
            ? OwnerRegistry::instance()->open(
                OwnerType::FIBER,
                OwnerRegistry::instance()->get($parentOwnerId)?->id(),
                ['spawned_at' => microtime(true)]
            )
            : OwnerRegistry::instance()->open(
                OwnerType::FIBER,
                null,
                ['spawned_at' => microtime(true)]
            );

        $fiber = new Fiber(function () use ($fn, $args, $parentContext, $fiberOwner) {
            // Restore parent context with fiber owner in new fiber
            $ctx = $parentContext->with([
                'owner_id' => $fiberOwner->id()->toString(),
                'owner_type' => 'fiber',
            ]);
            ContextStore::instance()->set($ctx);

            try {
                return $fn(...$args);
            } finally {
                $fiberOwner->close('fiber_completed');
            }
        });

        $coroutine = new FiberCoroutine($fiber);
        self::loop()->schedule($coroutine);

        return $coroutine;
    }

    /**
     * Cooperative sleep (yields to event loop).
     * Falls back to blocking sleep if runtime unavailable.
     */
    public static function sleep(float $seconds, ?CancellationToken $token = null): void
    {
        // Check cancellation before sleeping
        if ($token !== null && $token->isCancelled()) {
            throw new CancelledException($token->reason());
        }

        if (!self::available()) {
            // Fallback: blocking sleep
            usleep((int) ($seconds * 1_000_000));
            return;
        }

        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            // Not in fiber context, use blocking sleep
            usleep((int) ($seconds * 1_000_000));
            return;
        }

        self::loop()->sleepFiber($fiber, $seconds);
        Fiber::suspend(FiberCoroutine::SUSPEND_SLEEP);

        // Check cancellation after waking
        if ($token !== null && $token->isCancelled()) {
            throw new CancelledException($token->reason());
        }
    }

    /**
     * Yield control to event loop.
     */
    public static function yield(): void
    {
        if (!self::available()) {
            return;
        }

        $fiber = Fiber::getCurrent();
        if ($fiber !== null) {
            Fiber::suspend(FiberCoroutine::SUSPEND_YIELD);
        }
    }

    /**
     * Get current owner from context.
     */
    public static function owner(): ?string
    {
        return self::context()->ownerId();
    }

    /**
     * Get drain controller.
     */
    public static function drain(): \Nexphant\Core\Drain\DrainController
    {
        return \Nexphant\Core\Drain\DrainController::instance();
    }

    /**
     * Get owner registry.
     */
    public static function owners(): OwnerRegistry
    {
        return OwnerRegistry::instance();
    }

    /**
     * Run callable with specific owner.
     */
    public static function withOwner(OwnerType|string $type, callable $fn, array $metadata = []): mixed
    {
        $ownerType = is_string($type) ? OwnerType::from($type) : $type;
        $parentOwnerId = self::context()->ownerId();
        $parentOwner = $parentOwnerId ? OwnerRegistry::instance()->get($parentOwnerId) : null;

        $owner = OwnerRegistry::instance()->open(
            $ownerType,
            $parentOwner?->id(),
            $metadata
        );

        try {
            return self::withContext([
                'owner_id' => $owner->id()->toString(),
                'owner_type' => $ownerType->value,
                'parent_owner_id' => $parentOwner?->id()->toString(),
            ], $fn);
        } finally {
            $owner->close();
        }
    }

    /**
     * Get current runtime context.
     */
    public static function context(): RuntimeContext
    {
        return ContextStore::instance()->current();
    }

    /**
     * Run callable with specific context.
     */
    public static function withContext(RuntimeContext|array $context, callable $fn): mixed
    {
        if (is_array($context)) {
            $context = self::context()->with($context);
        }
        return ContextStore::instance()->runWith($context, $fn);
    }

    /**
     * Get current trace ID.
     */
    public static function traceId(): ?string
    {
        return self::context()->traceId();
    }

    /**
     * Run event loop until all coroutines complete.
     */
    public static function run(): void
    {
        if (!self::available()) {
            return;
        }

        self::loop()->run();
    }

    /**
     * Stop event loop.
     */
    public static function stop(): void
    {
        if (self::$loop !== null) {
            self::$loop->stop();
        }
    }
}
