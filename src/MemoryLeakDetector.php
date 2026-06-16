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

class MemoryLeakDetector
{
    private array $snapshots = [];
    private array $objectCounts = [];
    private array $leakSuspects = [];

    public function snapshot(string $label = ''): void
    {
        $label = $label ?: 'snap_' . count($this->snapshots);
        $this->snapshots[$label] = [
            'time' => microtime(true),
            'memory' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'objects' => $this->countObjects(),
        ];
    }

    public function compare(string $from, string $to): array
    {
        if (!isset($this->snapshots[$from]) || !isset($this->snapshots[$to])) {
            return ['error' => 'Snapshot not found'];
        }

        $s1 = $this->snapshots[$from];
        $s2 = $this->snapshots[$to];

        $memDiff = $s2['memory'] - $s1['memory'];
        $objDiff = [];

        foreach ($s2['objects'] as $class => $count) {
            $prev = $s1['objects'][$class] ?? 0;
            if ($count > $prev) {
                $objDiff[$class] = $count - $prev;
            }
        }

        arsort($objDiff);

        return [
            'memory_diff' => $memDiff,
            'memory_diff_human' => $this->formatBytes($memDiff),
            'time_diff' => $s2['time'] - $s1['time'],
            'object_growth' => array_slice($objDiff, 0, 10, true),
            'suspects' => $this->analyzeSuspects($objDiff, $memDiff),
        ];
    }

    public function track(callable $fn, int $iterations = 10): array
    {
        gc_collect_cycles();
        $this->snapshot('before');

        for ($i = 0; $i < $iterations; $i++) {
            $fn();
            gc_collect_cycles();
            $this->snapshot("iter_{$i}");
        }

        $this->snapshot('after');

        $results = $this->compare('before', 'after');
        $results['per_iteration'] = $this->formatBytes($results['memory_diff'] / $iterations);
        $results['iterations'] = $iterations;
        $results['trend'] = $this->analyzeTrend();

        return $results;
    }

    private function countObjects(): array
    {
        $counts = [];
        foreach (get_declared_classes() as $class) {
            // Skip internal classes
            try {
                $ref = new \ReflectionClass($class);
                if ($ref->isInternal())
                    continue;
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Use debug_zval_dump alternative
        if (function_exists('gc_status')) {
            $gc = gc_status();
            $counts['__gc_runs'] = $gc['runs'] ?? 0;
            $counts['__gc_collected'] = $gc['collected'] ?? 0;
        }

        return $counts;
    }

    private function analyzeSuspects(array $objDiff, int $memDiff): array
    {
        $suspects = [];

        if ($memDiff > 1024 * 1024) { // > 1MB
            $suspects[] = [
                'type' => 'high_memory_growth',
                'severity' => 'high',
                'detail' => "Memory grew by {$this->formatBytes($memDiff)}",
            ];
        }

        foreach ($objDiff as $class => $count) {
            if ($count > 100) {
                $suspects[] = [
                    'type' => 'object_accumulation',
                    'severity' => $count > 1000 ? 'high' : 'medium',
                    'class' => $class,
                    'count' => $count,
                ];
            }
        }

        return $suspects;
    }

    private function analyzeTrend(): string
    {
        $snaps = array_values($this->snapshots);
        if (count($snaps) < 3)
            return 'insufficient_data';

        $increasing = 0;
        for ($i = 1; $i < count($snaps); $i++) {
            if ($snaps[$i]['memory'] > $snaps[$i - 1]['memory']) {
                $increasing++;
            }
        }

        $ratio = $increasing / (count($snaps) - 1);
        if ($ratio > 0.8)
            return 'leak_likely';
        if ($ratio > 0.5)
            return 'possible_leak';
        return 'stable';
    }

    public function reset(): void
    {
        $this->snapshots = [];
        $this->objectCounts = [];
        $this->leakSuspects = [];
    }

    public function getSnapshots(): array
    {
        return $this->snapshots;
    }

    private function formatBytes(int $bytes): string
    {
        $sign = $bytes < 0 ? '-' : '';
        $bytes = abs($bytes);
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return $sign . round($bytes, 2) . ' ' . $units[$i];
    }
}
