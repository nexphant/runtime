<?php

declare(strict_types=1);

namespace nexphant\Runtime\Metrics;

/**
 * Runtime metrics collector
 */
final class MetricsCollector
{
    private static ?self $instance = null;
    private array $counters = [];
    private array $histograms = [];
    private array $gauges = [];

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function increment(string $name, int $value = 1, array $labels = []): void
    {
        // Merge runtime context labels if available
        $labels = $this->mergeRuntimeLabels($labels);
        
        $key = $this->key($name, $labels);
        if (!isset($this->counters[$key])) {
            $this->counters[$key] = ['name' => $name, 'value' => 0, 'labels' => $labels];
        }
        $this->counters[$key]['value'] += $value;
    }

    public function observe(string $name, float $value, array $labels = []): void
    {
        // Merge runtime context labels if available
        $labels = $this->mergeRuntimeLabels($labels);
        
        $key = $this->key($name, $labels);
        if (!isset($this->histograms[$key])) {
            $this->histograms[$key] = [
                'name' => $name,
                'labels' => $labels,
                'values' => [],
                'count' => 0,
                'sum' => 0.0,
            ];
        }
        $this->histograms[$key]['values'][] = $value;
        $this->histograms[$key]['count']++;
        $this->histograms[$key]['sum'] += $value;
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
        // Merge runtime context labels if available
        $labels = $this->mergeRuntimeLabels($labels);
        
        $key = $this->key($name, $labels);
        $this->gauges[$key] = ['name' => $name, 'value' => $value, 'labels' => $labels];
    }

    public function getCounters(): array
    {
        return array_values($this->counters);
    }

    public function getHistograms(): array
    {
        return array_values($this->histograms);
    }

    public function getGauges(): array
    {
        return array_values($this->gauges);
    }

    public function all(): array
    {
        return [
            'counters' => $this->getCounters(),
            'histograms' => $this->getHistograms(),
            'gauges' => $this->getGauges(),
        ];
    }

    public function reset(): void
    {
        $this->counters = [];
        $this->histograms = [];
        $this->gauges = [];
    }

    private function key(string $name, array $labels): string
    {
        ksort($labels);
        $labelStr = empty($labels) ? '' : ':' . json_encode($labels);
        return $name . $labelStr;
    }
    
    private function mergeRuntimeLabels(array $labels): array
    {
        if (!class_exists('\nexphant\Runtime\Runtime')) {
            return $labels;
        }
        
        try {
            if (!\nexphant\Runtime\Runtime::available()) {
                return $labels;
            }
            
            $ctx = \nexphant\Runtime\Runtime::context();
            
            // Only add safe, low-cardinality labels
            if ($ctx->ownerType() && !isset($labels['owner_type'])) {
                $labels['owner_type'] = $ctx->ownerType();
            }
            
            return $labels;
        } catch (\Throwable $e) {
            return $labels;
        }
    }
}
