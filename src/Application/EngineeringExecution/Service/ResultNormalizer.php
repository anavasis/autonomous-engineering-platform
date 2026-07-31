<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Service;

use Aep\Application\EngineeringExecution\Model\ExecutionSession;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\Execution\ExecutionResult;

final class ResultNormalizer
{
    /**
     * @param array<string, mixed> $extraContext
     */
    public function toExecutionResult(
        string $routerId,
        ExecutionSession $session,
        ProviderResult $result,
        array $extraContext = [],
    ): ExecutionResult {
        $context = array_merge([
            'providerId' => $session->providerId(),
            'sessionId' => $session->sessionId(),
            'filesChanged' => $result->filesChanged(),
            'usage' => $session->usage()->toArray(),
            'checkpointId' => is_array($session->checkpoint()) ? ($session->checkpoint()['id'] ?? null) : null,
            'artifacts' => $session->artifacts(),
            'timeline' => $session->timeline(),
        ], $extraContext);

        return match ($result->status()) {
            ProviderResult::SUCCEEDED => ExecutionResult::succeeded($routerId, $result->message(), $context),
            ProviderResult::REJECTED => ExecutionResult::rejected($routerId, $result->message(), $context),
            ProviderResult::CANCELLED => ExecutionResult::failed($routerId, $result->message() !== '' ? $result->message() : 'Cancelled.', $context + ['cancelled' => true]),
            ProviderResult::TIMED_OUT => ExecutionResult::failed($routerId, $result->message() !== '' ? $result->message() : 'Timed out.', $context + ['timedOut' => true]),
            default => ExecutionResult::failed($routerId, $result->message() !== '' ? $result->message() : 'Provider failed.', $context),
        };
    }
}
