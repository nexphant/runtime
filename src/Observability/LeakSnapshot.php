<?php

declare(strict_types=1);

namespace nexphant\Runtime\Observability;

use nexphant\Core\Resource\ResourceRegistry;
use nexphant\Core\Ownership\OwnerRegistry;

/**
 * Leak snapshot with grouping and analysis
 */
final class LeakSnapshot
{
    private array $leaks;
    private float $capturedAt;

    public function __construct()
    {
        $this->leaks = ResourceRegistry::instance()->listLeaks();
        $this->capturedAt = microtime(true);
    }

    public function all(): array
    {
        return $this->leaks;
    }

    public function count(): int
    {
        return count($this->leaks);
    }

    public function groupByOwner(): array
    {
        $groups = [];
        foreach ($this->leaks as $leak) {
            $ownerId = $leak['owner_id'] ?? 'unowned';
            if (!isset($groups[$ownerId])) {
                $groups[$ownerId] = [];
            }
            $groups[$ownerId][] = $leak;
        }
        return $groups;
    }

    public function groupByType(): array
    {
        $groups = [];
        foreach ($this->leaks as $leak) {
            $type = $leak['type'];
            if (!isset($groups[$type])) {
                $groups[$type] = [];
            }
            $groups[$type][] = $leak;
        }
        return $groups;
    }

    public function groupByScope(): array
    {
        $groups = [
            'owned' => [],
            'unowned' => [],
        ];

        foreach ($this->leaks as $leak) {
            if (isset($leak['owner_id']) && $leak['owner_id'] !== null) {
                $groups['owned'][] = $leak;
            } else {
                $groups['unowned'][] = $leak;
            }
        }

        return $groups;
    }

    public function oldest(int $limit = 10): array
    {
        $sorted = $this->leaks;
        usort($sorted, fn($a, $b) => $b['age'] <=> $a['age']);
        return array_slice($sorted, 0, $limit);
    }

    public function withStackTrace(): array
    {
        return array_filter($this->leaks, fn($leak) => isset($leak['stack_trace']));
    }

    public function toArray(): array
    {
        return [
            'captured_at' => $this->capturedAt,
            'count' => $this->count(),
            'by_owner' => $this->groupByOwner(),
            'by_type' => $this->groupByType(),
            'by_scope' => $this->groupByScope(),
            'oldest' => $this->oldest(10),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    public function toString(): string
    {
        $output = [];
        $output[] = "=== Resource Leak Report ===";
        $output[] = "Captured: " . date('Y-m-d H:i:s', (int)$this->capturedAt);
        $output[] = "Total leaks: " . $this->count();
        $output[] = "";

        if ($this->count() === 0) {
            $output[] = "No leaks detected.";
            return implode("\n", $output);
        }

        $output[] = "By Type:";
        foreach ($this->groupByType() as $type => $leaks) {
            $output[] = "  {$type}: " . count($leaks);
        }
        $output[] = "";

        $output[] = "By Owner:";
        foreach ($this->groupByOwner() as $ownerId => $leaks) {
            $owner = OwnerRegistry::instance()->get($ownerId);
            $ownerName = $owner ? $owner->name() : $ownerId;
            $output[] = "  {$ownerName}: " . count($leaks);
        }
        $output[] = "";

        $output[] = "Oldest Leaks:";
        foreach ($this->oldest(5) as $leak) {
            $age = round($leak['age'], 2);
            $output[] = "  [{$leak['type']}] {$leak['resource_id']} - age: {$age}s";
        }

        return implode("\n", $output);
    }
}
