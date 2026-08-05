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
        $checkpoint = $session->checkpoint();
        $workspacePath = null;
        if (is_array($checkpoint) && is_string($checkpoint['workspacePath'] ?? null) && trim((string) $checkpoint['workspacePath']) !== '') {
            $workspacePath = trim((string) $checkpoint['workspacePath']);
        }

        $filesChanged = $result->filesChanged();
        if ($filesChanged === [] && is_array($extraContext['filesChanged'] ?? null)) {
            $fromExtra = [];
            foreach ($extraContext['filesChanged'] as $path) {
                if (is_string($path) && trim($path) !== '') {
                    $fromExtra[] = trim($path);
                }
            }
            $filesChanged = $fromExtra;
        }

        $context = array_merge([
            'providerId' => $session->providerId(),
            'sessionId' => $session->sessionId(),
            'workspacePath' => $workspacePath,
            'filesChanged' => $filesChanged,
            'usage' => $session->usage()->toArray(),
            'checkpointId' => is_array($checkpoint) ? ($checkpoint['id'] ?? null) : null,
            'artifacts' => $session->artifacts(),
        ], $extraContext);

        // Prefer session workspace path over any stale extra override when present.
        if ($workspacePath !== null) {
            $context['workspacePath'] = $workspacePath;
        }
        if ($filesChanged !== []) {
            $context['filesChanged'] = $filesChanged;
        }

        // Do not persist huge provider timelines into run attributes.
        unset($context['timeline']);

        return match ($result->status()) {
            ProviderResult::SUCCEEDED => ExecutionResult::succeeded($routerId, $result->message(), $context),
            ProviderResult::REJECTED => ExecutionResult::rejected($routerId, $result->message(), $context),
            ProviderResult::CANCELLED => ExecutionResult::failed($routerId, $result->message() !== '' ? $result->message() : 'Cancelled.', $context + ['cancelled' => true]),
            ProviderResult::TIMED_OUT => ExecutionResult::failed($routerId, $result->message() !== '' ? $result->message() : 'Timed out.', $context + ['timedOut' => true]),
            default => ExecutionResult::failed($routerId, $result->message() !== '' ? $result->message() : 'Provider failed.', $context),
        };
    }
}
