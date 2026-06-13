<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Runtime\Observability;

use Nexph\Runtime\Runtime;

/**
 * Runtime health monitor.
 * Tracks worker health, degradation state, and system vitals.
 */
class HealthMonitor
{
    private array $checks = [];
    private array $history = [];
    private int $maxHistory = 100;
    private string $state = 'healthy';
    private array $degradationReasons = [];

    public function __construct()
    {
        $this->registerDefaultChecks();
    }

    private function registerDefaultChecks(): void
    {
        $this->registerCheck('runtime', function () {
            return [
                'healthy' => true,
                'available' => Runtime::available(),
                'capabilities' => Runtime::capabilities(),
            ];
        });

        $this->registerCheck('memory', function () {
            $usage = memory_get_usage(true);
            $limit = ini_get('memory_limit');
            $limitBytes = $this->parseMemoryLimit($limit);

            $healthy = $limitBytes === -1 || $usage < ($limitBytes * 0.9);

            return [
                'healthy' => $healthy,
                'usage' => $usage,
                'limit' => $limitBytes,
                'usage_percent' => $limitBytes > 0 ? ($usage / $limitBytes) * 100 : 0,
            ];
        });

        $this->registerCheck('cpu', function () {
            if (!function_exists('sys_getloadavg')) {
                return ['healthy' => true, 'available' => false];
            }

            $load = sys_getloadavg();
            $cores = $this->getCpuCores();
            $loadPerCore = $cores > 0 ? $load[0] / $cores : $load[0];

            return [
                'healthy' => $loadPerCore < 0.8,
                'load_1min' => $load[0],
                'load_5min' => $load[1],
                'load_15min' => $load[2],
                'cores' => $cores,
                'load_per_core' => $loadPerCore,
            ];
        });
    }

    public function registerCheck(string $name, callable $check): void
    {
        $this->checks[$name] = $check;
    }

    public function check(): array
    {
        $results = [];
        $allHealthy = true;
        $degraded = false;
        $this->degradationReasons = [];

        foreach ($this->checks as $name => $check) {
            try {
                $result = $check();
                $results[$name] = $result;

                if (isset($result['healthy']) && !$result['healthy']) {
                    $allHealthy = false;
                    $this->degradationReasons[] = $name;
                }

                if (isset($result['degraded']) && $result['degraded']) {
                    $degraded = true;
                    $this->degradationReasons[] = "{$name} (degraded)";
                }
            } catch (\Throwable $e) {
                $results[$name] = [
                    'healthy' => false,
                    'error' => $e->getMessage(),
                ];
                $allHealthy = false;
                $this->degradationReasons[] = "{$name} (error)";
            }
        }

        if (!$allHealthy) {
            $this->state = 'unhealthy';
        } elseif ($degraded) {
            $this->state = 'degraded';
        } else {
            $this->state = 'healthy';
        }

        $snapshot = [
            'timestamp' => microtime(true),
            'state' => $this->state,
            'checks' => $results,
            'degradation_reasons' => $this->degradationReasons,
        ];

        $this->recordHistory($snapshot);

        return $snapshot;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isHealthy(): bool
    {
        return $this->state === 'healthy';
    }

    public function isDegraded(): bool
    {
        return $this->state === 'degraded';
    }

    public function getDegradationReasons(): array
    {
        return $this->degradationReasons;
    }

    public function getHistory(): array
    {
        return $this->history;
    }

    private function recordHistory(array $snapshot): void
    {
        $this->history[] = $snapshot;

        if (count($this->history) > $this->maxHistory) {
            array_shift($this->history);
        }
    }

    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return -1;
        }

        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    private function getCpuCores(): int
    {
        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            return count($matches[0]);
        }

        return 1;
    }
}
