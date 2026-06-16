<?php

declare(strict_types=1);

namespace Nexphant\Runtime\Backpressure;

use Nexphant\Core\Cancellation\CancellationToken;
use Nexphant\Core\Context\ContextStore;
use Nexphant\Core\Ownership\OwnerType;

/**
 * Executor with bounded concurrency and queue
 */
final class BoundedExecutor
{
    private int $running = 0;
    private array $queue = [];
    private bool $shutdown = false;
    private array $metrics = [
        'queued' => 0,
        'completed' => 0,
        'rejected' => 0,
        'failed' => 0,
    ];

    public function __construct(
        private readonly int $maxConcurrency,
        private readonly int $maxQueueSize,
        private readonly string $rejectPolicy = 'reject'
    ) {
        if ($maxConcurrency <= 0) {
            throw new \InvalidArgumentException('Max concurrency must be positive');
        }
        if ($maxQueueSize < 0) {
            throw new \InvalidArgumentException('Max queue size cannot be negative');
        }
        if (!in_array($rejectPolicy, ['reject', 'wait', 'drop_oldest'], true)) {
            throw new \InvalidArgumentException('Invalid reject policy');
        }
    }

    public function submit(
        callable $task,
        ?CancellationToken $token = null
    ): bool {
        if ($this->shutdown) {
            $this->metrics['rejected']++;
            return false;
        }
        
        $token?->throwIfCancelled();
        
        $context = ContextStore::instance()->current();

        if ($this->running < $this->maxConcurrency) {
            $this->execute($task, $token, $context);
            return true;
        }

        if (count($this->queue) >= $this->maxQueueSize) {
            return $this->handleQueueFull($task, $token, $context);
        }

        $this->queue[] = ['task' => $task, 'token' => $token, 'context' => $context];
        $this->metrics['queued']++;
        return true;
    }

    public function metrics(): array
    {
        return [
            'running' => $this->running,
            'queued' => count($this->queue),
            'completed' => $this->metrics['completed'],
            'rejected' => $this->metrics['rejected'],
            'failed' => $this->metrics['failed'],
        ];
    }

    public function shutdown(?CancellationToken $token = null): void
    {
        $this->shutdown = true;
        $this->queue = [];
    }

    private function execute(callable $task, ?CancellationToken $token, $context): void
    {
        $this->running++;
        
        if (class_exists('\Nexphant\Runtime\Runtime') && \Nexphant\Runtime\Runtime::available()) {
            \Nexphant\Runtime\Runtime::spawn(function() use ($task, $token, $context) {
                $parentOwnerId = $context->ownerId();
                $taskOwner = \Nexphant\Runtime\Runtime::owners()->open(
                    \Nexphant\Core\Ownership\OwnerType::EXECUTOR_TASK,
                    $parentOwnerId ? \Nexphant\Runtime\Runtime::owners()->get($parentOwnerId)?->id() : null,
                    ['executor' => 'bounded']
                );
                
                try {
                    $token?->throwIfCancelled();
                    $taskContext = $context->with(['owner_id' => $taskOwner->id()->toString(), 'owner_type' => 'executor_task']);
                    \Nexphant\Core\Context\ContextStore::instance()->runWith($taskContext, $task);
                    $this->metrics['completed']++;
                } catch (\Throwable $e) {
                    $this->metrics['failed']++;
                    error_log("BoundedExecutor task failed: " . $e->getMessage());
                } finally {
                    $taskOwner->close('task_completed');
                    $this->running--;
                    $this->drainQueue();
                }
            });
        } else {
            try {
                $token?->throwIfCancelled();
                ContextStore::instance()->runWith($context, $task);
                $this->metrics['completed']++;
            } catch (\Throwable $e) {
                $this->metrics['failed']++;
                error_log("BoundedExecutor task failed: " . $e->getMessage());
            } finally {
                $this->running--;
                $this->drainQueue();
            }
        }
    }

    private function drainQueue(): void
    {
        while ($this->running < $this->maxConcurrency && !empty($this->queue)) {
            $item = array_shift($this->queue);
            $this->running++;
            try {
                $item['token']?->throwIfCancelled();
                ContextStore::instance()->runWith($item['context'], $item['task']);
                $this->metrics['completed']++;
            } catch (\Throwable $e) {
                $this->metrics['failed']++;
                error_log("BoundedExecutor task failed: " . $e->getMessage());
            } finally {
                $this->running--;
            }
        }
    }

    private function handleQueueFull(callable $task, ?CancellationToken $token, $context): bool
    {
        switch ($this->rejectPolicy) {
            case 'reject':
                $this->metrics['rejected']++;
                return false;

            case 'drop_oldest':
                if (!empty($this->queue)) {
                    array_shift($this->queue);
                }
                $this->queue[] = ['task' => $task, 'token' => $token, 'context' => $context];
                return true;

            case 'wait':
                $deadline = microtime(true) + 30;
                while (count($this->queue) >= $this->maxQueueSize) {
                    $token?->throwIfCancelled();
                    if (microtime(true) >= $deadline) {
                        $this->metrics['rejected']++;
                        return false;
                    }
                    if (class_exists('\Nexphant\Runtime\Runtime') && \Nexphant\Runtime\Runtime::available()) {
                        \Nexphant\Runtime\Runtime::sleep(0.001);
                    } else {
                        usleep(1_000);
                    }
                }
                $this->queue[] = ['task' => $task, 'token' => $token, 'context' => $context];
                $this->metrics['queued']++;
                return true;

            default:
                $this->metrics['rejected']++;
                return false;
        }
    }
}
