<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Validation;

use Aep\Application\Validation\ValidationPipeline;
use Aep\Application\Validation\ValidationRequest;
use Aep\Infrastructure\Validation\DeclarativeContextValidationStep;
use Tests\Support\Assert;

final class DeclarativeContextValidationEvidenceTest
{
    public function test_declared_paths_alone_no_longer_pass(): void
    {
        $report = $this->pipeline()->run(new ValidationRequest(
            'msn_v_1',
            '2026-08-05T12:00:00Z',
            ['declaredPaths' => ['README.md']]
        ));
        Assert::true($report->isFailed());
    }

    public function test_missing_provider_session_workspace_diff_patch_fails(): void
    {
        $report = $this->pipeline()->run(new ValidationRequest(
            'msn_v_2',
            '2026-08-05T12:00:00Z',
            [
                'declaredPaths' => ['README.md'],
                'providerId' => 'codex',
            ]
        ));
        Assert::true($report->isFailed());
        Assert::true(str_contains($report->reason(), 'sessionId'));
    }

    public function test_changed_file_matching_readme_passes(): void
    {
        $report = $this->pipeline()->run(new ValidationRequest(
            'msn_v_3',
            '2026-08-05T12:00:00Z',
            $this->full(['README.md'], ['README.md'])
        ));
        Assert::true($report->isPassed());
        Assert::true(str_contains($report->outcomes()[0]->message(), 'real provider execution'));
    }

    public function test_changed_file_outside_readme_fails(): void
    {
        $report = $this->pipeline()->run(new ValidationRequest(
            'msn_v_4',
            '2026-08-05T12:00:00Z',
            $this->full(['README.md'], ['src/Other.php'])
        ));
        Assert::true($report->isFailed());
        Assert::true(str_contains($report->reason(), 'outside allowed paths'));
    }

    public function test_traversal_path_fails(): void
    {
        $report = $this->pipeline()->run(new ValidationRequest(
            'msn_v_5',
            '2026-08-05T12:00:00Z',
            $this->full(['README.md'], ['../secrets.txt'])
        ));
        Assert::true($report->isFailed());
        Assert::true(str_contains($report->reason(), 'traversal') || str_contains($report->reason(), 'invalid'));
    }

    public function test_directory_allowlist_permits_descendants(): void
    {
        $report = $this->pipeline()->run(new ValidationRequest(
            'msn_v_6',
            '2026-08-05T12:00:00Z',
            $this->full(['src/'], ['src/Domain/Mission.php'])
        ));
        Assert::true($report->isPassed());
    }

    /**
     * @param list<string> $declared
     * @param list<string> $changed
     * @return array<string, mixed>
     */
    private function full(array $declared, array $changed): array
    {
        return [
            'declaredPaths' => $declared,
            'providerId' => 'codex',
            'sessionId' => 'esess_v',
            'workspacePath' => '/tmp/ws',
            'filesChanged' => $changed,
            'patchId' => 'patch_v',
            'patchStatus' => 'ready',
            'mergeReady' => true,
            'artifacts' => ['diff' => 'a1'],
        ];
    }

    private function pipeline(): ValidationPipeline
    {
        return new ValidationPipeline([new DeclarativeContextValidationStep()]);
    }
}
