<?php

namespace Nexph\Runtime\Config;

class RuntimeConfigResolver
{
    private static function cpuCores(): int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $cores = @file_get_contents('/proc/cpuinfo');
            if ($cores && preg_match_all('/^processor/m', $cores, $matches)) {
                return count($matches[0]);
            }
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $cores = @shell_exec('sysctl -n hw.ncpu');
            if ($cores) {
                return (int)trim($cores);
            }
        }
        return 4;
    }

    private static function getRuntimeModePreset(string $mode): array
    {
        $cores = self::cpuCores();

        return match($mode) {
            'low_latency' => [
                'workers' => $cores,
                'max_accept_per_tick' => 8,
                'max_read_callbacks_per_tick' => 256,
                'max_write_callbacks_per_tick' => 256,
                'max_deferred_per_tick' => 256,
            ],
            'balanced' => [
                'workers' => $cores * 2,
                'max_accept_per_tick' => 16,
                'max_read_callbacks_per_tick' => 512,
                'max_write_callbacks_per_tick' => 512,
                'max_deferred_per_tick' => 512,
            ],
            'throughput' => [
                'workers' => $cores * 4,
                'max_accept_per_tick' => 64,
                'max_read_callbacks_per_tick' => 2048,
                'max_write_callbacks_per_tick' => 2048,
                'max_deferred_per_tick' => 2048,
            ],
            'custom' => [],
            default => self::getRuntimeModePreset('balanced'),
        };
    }

    public static function resolve(array $userConfig = []): RuntimeConfig
    {
        $defaults = [
            'runtime_mode' => 'balanced',
            'workers' => null,
            'event_loop' => 'auto',
            'socket_driver' => 'stream',
            'max_accept_per_tick' => null,
            'max_read_callbacks_per_tick' => null,
            'max_write_callbacks_per_tick' => null,
            'max_deferred_per_tick' => null,
            'performance_mode' => false,
            'cpu_cores' => self::cpuCores(),
        ];

        $config = array_merge($defaults, $userConfig);

        $mode = $config['runtime_mode'];
        $preset = self::getRuntimeModePreset($mode);

        foreach ($preset as $key => $value) {
            if ($config[$key] === null) {
                $config[$key] = $value;
            }
        }

        if (getenv('NEXPH_SOCKET')) {
            $config['socket_driver'] = getenv('NEXPH_SOCKET');
        }
        if (getenv('NEXPH_LOOP')) {
            $config['event_loop'] = getenv('NEXPH_LOOP');
        }

        return new RuntimeConfig($config);
    }
}
