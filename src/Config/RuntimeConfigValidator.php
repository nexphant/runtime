<?php

namespace Nexphant\Runtime\Config;

final class RuntimeConfigValidator
{
    private array $errors = [];

    public function validate(RuntimeConfig $config): bool
    {
        $this->errors = [];

        $workers = $config->get('worker_count', $config->get('workers', 1));
        if (!is_int($workers) || $workers < 1) {
            $this->errors[] = 'worker_count must be at least 1';
        }

        $driver = $config->get('queue_driver', 'memory');
        if (!in_array($driver, ['memory', 'apcu', 'redis', 'database'], true)) {
            $this->errors[] = 'queue_driver is invalid';
        }

        $timeout = $config->get('drain_timeout', 10);
        if (!is_numeric($timeout) || $timeout <= 0) {
            $this->errors[] = 'drain_timeout must be positive';
        }

        $redisUrl = $config->get('redis_url', '');
        if ($redisUrl !== '') {
            $host = parse_url((string) $redisUrl, PHP_URL_HOST) ?? '';
            if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|::1$|localhost$)/i', $host)) {
                // allow private hosts in non-production only — log warning
                error_log("Warning: redis_url points to private/loopback host: {$host}");
            }
        }

        foreach (['runtime_discipline', 'object_tracking', 'resource_trace', 'leak_detection'] as $flag) {
            $value = $config->get($flag, false);
            if (!is_bool($value)) {
                $this->errors[] = $flag . ' must be boolean';
            }
        }

        return $this->errors === [];
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
