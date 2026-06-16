<?php

declare(strict_types=1);

namespace Nexphant\Runtime\Observability;

use Nexphant\Core\Context\ContextStore;

/**
 * Processor that adds runtime context to log records
 */
final class ContextLogProcessor
{
    public function __invoke(array $record): array
    {
        $context = ContextStore::instance()->current();
        
        $runtimeContext = [];
        
        if ($traceId = $context->traceId()) {
            $runtimeContext['trace_id'] = $traceId;
        }
        
        if ($spanId = $context->spanId()) {
            $runtimeContext['span_id'] = $spanId;
        }
        
        if ($parentSpanId = $context->parentSpanId()) {
            $runtimeContext['parent_span_id'] = $parentSpanId;
        }
        
        if ($ownerId = $context->ownerId()) {
            $runtimeContext['owner_id'] = $ownerId;
        }
        
        if ($ownerType = $context->ownerType()) {
            $runtimeContext['owner_type'] = $ownerType;
        }
        
        if ($workerId = $context->workerId()) {
            $runtimeContext['worker_id'] = $workerId;
        }
        
        if ($jobId = $context->jobId()) {
            $runtimeContext['job_id'] = $jobId;
        }
        
        if ($requestId = $context->requestId()) {
            $runtimeContext['request_id'] = $requestId;
        }
        
        if (!empty($runtimeContext)) {
            $record['context'] = array_merge($runtimeContext, $record['context'] ?? []);
        }
        
        return $record;
    }
}
