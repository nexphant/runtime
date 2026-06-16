<?php
declare(strict_types=1);

namespace nexphant\Runtime\Backpressure;

final class ExecutorRegistry
{
    private static ?self $instance = null;
    private array $executors = [];

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register(string $name, BoundedExecutor $executor): void
    {
        $this->executors[$name] = $executor;
    }

    public function unregister(string $name): void
    {
        unset($this->executors[$name]);
    }

    public function all(): array
    {
        return $this->executors;
    }

    public function checkStuck(int $threshold = 1000): array
    {
        $stuck = [];
        foreach ($this->executors as $name => $executor) {
            $metrics = $executor->metrics();
            if ($metrics['queued'] >= $threshold || ($metrics['running'] > 0 && $metrics['queued'] > 0)) {
                $stuck[] = [
                    'name' => $name,
                    'metrics' => $metrics,
                ];
            }
        }
        return $stuck;
    }
}
