<?php
declare(strict_types=1);

namespace Nexph\Runtime;

use Nexph\Core\Ownership\OwnerType;

final class ErrorBoundary
{
    public static function run(callable $fn, ?callable $onError = null): mixed
    {
        if (!Runtime::available()) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                if ($onError) {
                    $onError($e);
                }
                throw $e;
            }
        }

        $parentOwnerId = Runtime::context()->ownerId();
        $owner = Runtime::owners()->open(
            OwnerType::FIBER,
            $parentOwnerId ? Runtime::owners()->get($parentOwnerId)?->id() : null,
            ['boundary' => true]
        );

        try {
            return Runtime::withContext(['owner_id' => $owner->id()->toString()], $fn);
        } catch (\Throwable $e) {
            error_log("ErrorBoundary caught: " . $e->getMessage());
            
            if ($onError) {
                $onError($e);
            }
            
            $owner->close('error_' . get_class($e));
            throw $e;
        } finally {
            if ($owner->isAlive()) {
                $owner->close('completed');
            }
        }
    }
}
