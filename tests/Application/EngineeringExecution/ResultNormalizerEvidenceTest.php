<?php

declare(strict_types=1);

namespace Tests\Application\EngineeringExecution;

use Aep\Application\EngineeringExecution\Model\ExecutionSession;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Service\ResultNormalizer;
use Tests\Support\Assert;

final class ResultNormalizerEvidenceTest
{
    public function test_includes_workspace_path_and_omits_timeline(): void
    {
        $session = new ExecutionSession(
            'esess_rn',
            'msn_rn',
            'run_rn',
            'codex',
            ExecutionSession::STATUS_SUCCEEDED,
            '2026-08-05T12:00:00Z',
            '2026-08-05T12:00:01Z',
            'ok',
            [],
            []
        );
        $session->setCheckpoint([
            'id' => 'cp_rn',
            'phase' => 'finished',
            'workspacePath' => '/tmp/ws_rn',
            'at' => '2026-08-05T12:00:01Z',
        ]);
        $session->setArtifacts(['diff' => 'art_diff']);
        $session->setStatus(ExecutionSession::STATUS_SUCCEEDED, '2026-08-05T12:00:02Z', 'noisy event that must not persist');

        $result = (new ResultNormalizer())->toExecutionResult(
            'provider_routing',
            $session,
            new ProviderResult(ProviderResult::SUCCEEDED, 'ok', ['README.md']),
            ['filesChanged' => ['README.md']]
        );

        Assert::true($result->isSucceeded());
        $ctx = $result->context();
        Assert::same('codex', $ctx['providerId'] ?? null);
        Assert::same('esess_rn', $ctx['sessionId'] ?? null);
        Assert::same('/tmp/ws_rn', $ctx['workspacePath'] ?? null);
        Assert::same(['README.md'], $ctx['filesChanged'] ?? null);
        Assert::same('cp_rn', $ctx['checkpointId'] ?? null);
        Assert::true(is_array($ctx['artifacts'] ?? null));
        Assert::true(is_array($ctx['usage'] ?? null));
        Assert::true(!array_key_exists('timeline', $ctx));
    }
}
