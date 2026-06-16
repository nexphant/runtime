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

class MemoryMonitor
{
    private array $samples = [];
    private int $sampleHead = 0;
    private int $maxSamples = 100;
    private int $leakThresholdBytes = 1024 * 1024; // 1MB
    private int $leakWindowSamples = 20;
    private float $leakGrowthRate = 0.1; // 10% growth
    private ?array $lastLeak = null;

    public function sample(): void
    {
        $this->samples[] = [
            'time' => microtime(true),
            'usage' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
        ];
        if ($this->sampleCount() > $this->maxSamples) {
            $this->sampleHead++;
            if ($this->sampleHead > 64 && $this->sampleHead * 2 >= count($this->samples)) {
                $this->samples = array_slice($this->samples, $this->sampleHead);
                $this->sampleHead = 0;
            }
        }
    }

    public function detectLeak(): bool
    {
        if ($this->sampleCount() < $this->leakWindowSamples) {
            return false;
        }

        $window = array_slice($this->activeSamples(), -$this->leakWindowSamples);
        $first = $window[0]['usage'];
        $last = $window[count($window) - 1]['usage'];
        $growth = $last - $first;
        $growthRate = $first > 0 ? $growth / $first : 0;

        // Check monotonic increase
        $increasing = 0;
        for ($i = 1; $i < count($window); $i++) {
            if ($window[$i]['usage'] > $window[$i - 1]['usage']) {
                $increasing++;
            }
        }
        $monotonicRatio = $increasing / (count($window) - 1);

        if ($growth > $this->leakThresholdBytes && $growthRate > $this->leakGrowthRate && $monotonicRatio > 0.7) {
            $this->lastLeak = [
                'growth' => $growth,
                'rate' => $growthRate,
                'monotonic' => $monotonicRatio,
                'current' => $last,
                'peak' => memory_get_peak_usage(true),
            ];
            return true;
        }
        return false;
    }

    public function getReport(): string
    {
        if (!$this->lastLeak) {
            return 'No leak detected';
        }
        return sprintf(
            "Growth: %s, Rate: %.1f%%, Monotonic: %.0f%%, Current: %s, Peak: %s",
            $this->formatBytes($this->lastLeak['growth']),
            $this->lastLeak['rate'] * 100,
            $this->lastLeak['monotonic'] * 100,
            $this->formatBytes($this->lastLeak['current']),
            $this->formatBytes($this->lastLeak['peak'])
        );
    }

    public function getStats(): array
    {
        if (empty($this->samples)) {
            return ['current' => 0, 'peak' => 0, 'samples' => 0];
        }
        $active = $this->activeSamples();
        $last = end($active);
        return [
            'current' => $last['usage'],
            'peak' => $last['peak'],
            'samples' => count($active),
            'trend' => $this->calculateTrend(),
        ];
    }

    private function calculateTrend(): string
    {
        if ($this->sampleCount() < 5)
            return 'stable';
        $recent = array_slice($this->activeSamples(), -5);
        $diff = end($recent)['usage'] - reset($recent)['usage'];
        if ($diff > 100 * 1024)
            return 'increasing';
        if ($diff < -100 * 1024)
            return 'decreasing';
        return 'stable';
    }

    public function setThreshold(int $bytes): self
    {
        $this->leakThresholdBytes = $bytes;
        return $this;
    }

    public function setGrowthRate(float $rate): self
    {
        $this->leakGrowthRate = $rate;
        return $this;
    }

    public function reset(): void
    {
        $this->samples = [];
        $this->sampleHead = 0;
        $this->lastLeak = null;
    }

    private function sampleCount(): int
    {
        return count($this->samples) - $this->sampleHead;
    }

    private function activeSamples(): array
    {
        return $this->sampleHead === 0 ? $this->samples : array_slice($this->samples, $this->sampleHead);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
