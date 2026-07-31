<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Service;

use Aep\Application\Planning\Model\ProgramNode;

final class FailureRecoveryPolicy
{
    /**
     * @return array{action: string, reason: string}
     */
    public function decide(ProgramNode $node): array
    {
        $policy = $node->failurePolicy();
        $attempt = is_int($node->retry()['attempt'] ?? null) ? (int) $node->retry()['attempt'] : 0;
        $max = is_int($node->retry()['maxAttempts'] ?? null) ? (int) $node->retry()['maxAttempts'] : 2;

        if ($policy === 'retry' && $attempt < $max) {
            return ['action' => 'retry', 'reason' => 'attempt ' . ($attempt + 1) . '/' . $max];
        }
        if ($policy === 'skip' || ($policy === 'retry' && $attempt >= $max && $policy !== 'halt')) {
            if ($policy === 'skip') {
                return ['action' => 'skip', 'reason' => 'configured skip'];
            }
        }
        if ($policy === 'replan') {
            return ['action' => 'replan', 'reason' => 'configured replan'];
        }
        if ($policy === 'retry' && $attempt >= $max) {
            return ['action' => 'replan', 'reason' => 'retries exhausted'];
        }

        return ['action' => 'halt', 'reason' => 'halt on failure'];
    }
}
