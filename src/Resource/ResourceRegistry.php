<?php

declare(strict_types=1);

namespace Nexph\Runtime\Resource;

use Nexph\Core\Ownership\OwnerId;

/**
 * Registry for tracking runtime resources and their ownership
 */
final class ResourceRegistry
{
    private static ?self $instance = null;
    private array $resources = [];
    private array $ownerIndex = [];
    private array $stackTraces = [];
    private bool $captureStackTrace = false;

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

    public function enableStackTrace(bool $enable = true): void
    {
        $this->captureStackTrace = $enable;
    }

    public function track(
        object $resource,
        string $type,
        OwnerId|string|null $owner = null
    ): void {
        $resourceId = $resource instanceof RuntimeResource 
            ? $resource->resourceId() 
            : (string)spl_object_id($resource);

        $ownerId = $owner instanceof OwnerId ? $owner->toString() : $owner;

        $this->resources[$resourceId] = [
            'resource' => $resource,
            'type' => $type,
            'owner_id' => $ownerId,
            'created_at' => microtime(true),
            'released' => false,
        ];

        if ($ownerId !== null) {
            if (!isset($this->ownerIndex[$ownerId])) {
                $this->ownerIndex[$ownerId] = [];
            }
            $this->ownerIndex[$ownerId][$resourceId] = true;
        }

        if ($this->captureStackTrace) {
            $this->stackTraces[$resourceId] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        }
    }

    public function release(string $resourceId): void
    {
        if (!isset($this->resources[$resourceId])) {
            return;
        }

        $entry = $this->resources[$resourceId];
        $resource = $entry['resource'];

        if ($resource instanceof RuntimeResource && !$resource->isReleased()) {
            $resource->release();
        }

        $this->resources[$resourceId]['released'] = true;
        $this->resources[$resourceId]['released_at'] = microtime(true);

        // Remove from owner index
        if ($entry['owner_id'] !== null) {
            unset($this->ownerIndex[$entry['owner_id']][$resourceId]);
        }

        unset($this->stackTraces[$resourceId]);
    }

    public function releaseByOwner(OwnerId|string $owner, string $reason = ''): void
    {
        $ownerId = $owner instanceof OwnerId ? $owner->toString() : $owner;

        if (!isset($this->ownerIndex[$ownerId])) {
            return;
        }

        $resourceIds = array_keys($this->ownerIndex[$ownerId]);
        foreach ($resourceIds as $resourceId) {
            if (isset($this->resources[$resourceId]) && $reason) {
                $this->resources[$resourceId]['release_reason'] = $reason;
            }
            $this->release((string)$resourceId);
        }

        unset($this->ownerIndex[$ownerId]);
    }

    public function listByOwner(OwnerId|string $owner): array
    {
        $ownerId = $owner instanceof OwnerId ? $owner->toString() : $owner;

        if (!isset($this->ownerIndex[$ownerId])) {
            return [];
        }

        $resources = [];
        foreach (array_keys($this->ownerIndex[$ownerId]) as $resourceId) {
            if (isset($this->resources[$resourceId])) {
                $resources[] = $this->resources[$resourceId];
            }
        }
        return $resources;
    }

    public function listLeaks(): array
    {
        $leaks = [];
        $now = microtime(true);

        foreach ($this->resources as $resourceId => $entry) {
            if ($entry['released']) {
                continue;
            }

            $age = $now - $entry['created_at'];
            $leaks[] = [
                'resource_id' => $resourceId,
                'type' => $entry['type'],
                'owner_id' => $entry['owner_id'],
                'age' => $age,
                'created_at' => $entry['created_at'],
                'stack_trace' => $this->stackTraces[$resourceId] ?? null,
            ];
        }

        return $leaks;
    }

    public function stats(): array
    {
        $byType = [];
        $byOwner = [];
        $total = 0;
        $released = 0;
        $leaked = 0;

        foreach ($this->resources as $entry) {
            $type = $entry['type'];
            $ownerId = $entry['owner_id'] ?? 'unowned';

            if (!isset($byType[$type])) {
                $byType[$type] = ['total' => 0, 'released' => 0, 'leaked' => 0];
            }
            if (!isset($byOwner[$ownerId])) {
                $byOwner[$ownerId] = ['total' => 0, 'released' => 0, 'leaked' => 0];
            }

            $byType[$type]['total']++;
            $byOwner[$ownerId]['total']++;
            $total++;

            if ($entry['released']) {
                $byType[$type]['released']++;
                $byOwner[$ownerId]['released']++;
                $released++;
            } else {
                $byType[$type]['leaked']++;
                $byOwner[$ownerId]['leaked']++;
                $leaked++;
            }
        }

        return [
            'total' => $total,
            'released' => $released,
            'leaked' => $leaked,
            'by_type' => $byType,
            'by_owner' => $byOwner,
        ];
    }
}
