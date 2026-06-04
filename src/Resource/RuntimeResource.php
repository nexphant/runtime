<?php

declare(strict_types=1);

namespace Nexph\Runtime\Resource;

use Nexph\Core\Ownership\OwnerId;

/**
 * Contract for trackable runtime resources
 */
interface RuntimeResource
{
    /**
     * Get unique resource identifier
     */
    public function resourceId(): string;

    /**
     * Get resource type (e.g., 'db_connection', 'redis_connection', 'file_handle')
     */
    public function resourceType(): string;

    /**
     * Get owner ID
     */
    public function ownerId(): ?string;

    /**
     * Release the resource
     */
    public function release(): void;

    /**
     * Check if resource is released
     */
    public function isReleased(): bool;
}
