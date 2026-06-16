<?php
declare(strict_types=1);

namespace nexphant\Runtime\Doctor;

use nexphant\Runtime\Runtime;
use nexphant\Runtime\Fiber\FiberRegistry;
use nexphant\Core\Drain\DrainController;

final class RuntimeDoctor
{
    public function diagnose(): array
    {
        $issues = [];
        
        $orphanOwners = $this->checkOrphanOwners();
        if (!empty($orphanOwners)) {
            $issues[] = [
                'type' => 'orphan_owner',
                'severity' => 'warning',
                'count' => count($orphanOwners),
                'details' => $orphanOwners,
            ];
        }
        
        $leakedResources = $this->checkLeakedResources();
        if (!empty($leakedResources)) {
            $issues[] = [
                'type' => 'leaked_resource',
                'severity' => 'error',
                'count' => count($leakedResources),
                'details' => $leakedResources,
            ];
        }
        
        $suspendedFibers = $this->checkSuspendedFibers();
        if (!empty($suspendedFibers)) {
            $issues[] = [
                'type' => 'suspended_fiber',
                'severity' => 'warning',
                'count' => count($suspendedFibers),
                'details' => $suspendedFibers,
            ];
        }
        
        $drainState = DrainController::instance()->state();
        if ($drainState === 'draining') {
            $stats = DrainController::instance()->stats();
            if ($stats['drain_duration'] > 60) {
                $issues[] = [
                    'type' => 'drain_stuck',
                    'severity' => 'error',
                    'details' => $stats,
                ];
            }
        }
        
        $extensionIssues = $this->checkMissingExtensions();
        if (!empty($extensionIssues)) {
            $issues[] = [
                'type' => 'missing_extension',
                'severity' => 'warning',
                'details' => $extensionIssues,
            ];
        }
        
        $stuckExecutors = $this->checkExecutors();
        if (!empty($stuckExecutors)) {
            $issues[] = [
                'type' => 'executor_stuck',
                'severity' => 'error',
                'count' => count($stuckExecutors),
                'details' => $stuckExecutors,
            ];
        }
        
        return [
            'timestamp' => date('c'),
            'healthy' => empty($issues),
            'issues' => $issues,
        ];
    }
    
    private function checkExecutors(): array
    {
        if (!class_exists('\nexphant\Runtime\Backpressure\ExecutorRegistry')) {
            return [];
        }
        
        return \nexphant\Runtime\Backpressure\ExecutorRegistry::instance()->checkStuck();
    }
    
    private function checkMissingExtensions(): array
    {
        $missing = [];
        $suggested = ['apcu', 'redis', 'mysqli', 'pgsql', 'zlib'];
        
        foreach ($suggested as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = [
                    'extension' => $ext,
                    'optional' => true,
                ];
            }
        }
        
        return $missing;
    }
    
    private function checkOrphanOwners(): array
    {
        if (!Runtime::available()) {
            return [];
        }
        
        $owners = Runtime::owners()->alive();
        $orphans = [];
        
        foreach ($owners as $owner) {
            if ($owner->parentId && !Runtime::owners()->get($owner->parentId)) {
                $orphans[] = [
                    'id' => $owner->id->toString(),
                    'type' => $owner->type->value,
                    'parent_id' => $owner->parentId->toString(),
                ];
            }
        }
        
        return $orphans;
    }
    
    private function checkLeakedResources(): array
    {
        if (!Runtime::available()) {
            return [];
        }
        
        return Runtime::resources()->listLeaks();
    }
    
    private function checkSuspendedFibers(float $minAge = 10.0): array
    {
        return FiberRegistry::instance()->suspendedFibers($minAge);
    }
}
