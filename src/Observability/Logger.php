<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace nexphant\Runtime\Observability;

/**
 * Structured runtime logger.
 * Provides context-aware logging for queues, workers, channels, timers, and runtime events.
 */
class Logger
{
    private string $level = 'info';
    private array $context = [];
    private $output;
    private bool $structured = false;

    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'critical' => 4,
    ];

    public function __construct($output = null, string $level = 'info', bool $structured = false)
    {
        $this->output = $output ?? STDOUT;
        $this->level = $level;
        $this->structured = $structured;
    }

    public function setLevel(string $level): void
    {
        $this->level = $level;
    }

    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    public function withContext(array $context): self
    {
        $logger = clone $this;
        $logger->context = array_merge($this->context, $context);
        return $logger;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        // Merge runtime context if available
        $runtimeContext = $this->getRuntimeContext();
        $context = array_merge($this->context, $runtimeContext, $context);

        if ($this->structured) {
            $this->logStructured($level, $message, $context);
        } else {
            $this->logFormatted($level, $message, $context);
        }
    }

    private function shouldLog(string $level): bool
    {
        $currentLevel = self::LEVELS[$this->level] ?? 1;
        $messageLevel = self::LEVELS[$level] ?? 1;
        return $messageLevel >= $currentLevel;
    }

    private function getRuntimeContext(): array
    {
        if (!class_exists('\nexphant\Runtime\Runtime')) {
            return [];
        }

        try {
            if (!\nexphant\Runtime\Runtime::available()) {
                return [];
            }

            $ctx = \nexphant\Runtime\Runtime::context();
            $result = [];

            if ($ctx->traceId()) {
                $result['trace_id'] = $ctx->traceId();
            }
            if ($ctx->spanId()) {
                $result['span_id'] = $ctx->spanId();
            }
            if ($ctx->ownerId()) {
                $result['owner_id'] = $ctx->ownerId();
            }
            if ($ctx->ownerType()) {
                $result['owner_type'] = $ctx->ownerType();
            }
            if ($ctx->workerId()) {
                $result['worker_id'] = $ctx->workerId();
            }
            if ($ctx->jobId()) {
                $result['job_id'] = $ctx->jobId();
            }

            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function logStructured(string $level, string $message, array $context): void
    {
        $entry = [
            'timestamp' => microtime(true),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        fwrite($this->output, json_encode($entry) . "\n");
    }

    private function logFormatted(string $level, string $message, array $context): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelStr = strtoupper($level);

        $contextStr = '';
        if (!empty($context)) {
            $parts = [];
            foreach ($context as $key => $value) {
                if (is_scalar($value)) {
                    $parts[] = "{$key}={$value}";
                } elseif (is_array($value)) {
                    $parts[] = "{$key}=" . json_encode($value);
                }
            }
            if (!empty($parts)) {
                $contextStr = ' [' . implode(', ', $parts) . ']';
            }
        }

        $line = "[{$timestamp}] {$levelStr}: {$message}{$contextStr}\n";
        fwrite($this->output, $line);
    }

    public function queueJob(string $jobId, string $jobName, string $status): void
    {
        $this->info("Queue job {$status}", [
            'job_id' => $jobId,
            'job_name' => $jobName,
            'status' => $status,
        ]);
    }

    public function workerEvent(int $workerId, string $event, array $context = []): void
    {
        $this->info("Worker {$event}", array_merge([
            'worker_id' => $workerId,
            'event' => $event,
        ], $context));
    }

    public function timerEvent(int $timerId, string $event, array $context = []): void
    {
        $this->debug("Timer {$event}", array_merge([
            'timer_id' => $timerId,
            'event' => $event,
        ], $context));
    }

    public function channelEvent(string $channelId, string $event, array $context = []): void
    {
        $this->debug("Channel {$event}", array_merge([
            'channel_id' => $channelId,
            'event' => $event,
        ], $context));
    }

    public function runtimeEvent(string $event, array $context = []): void
    {
        $this->info("Runtime {$event}", array_merge([
            'event' => $event,
        ], $context));
    }
}
