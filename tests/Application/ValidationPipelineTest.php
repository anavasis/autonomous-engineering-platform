<?php

declare(strict_types=1);

namespace Tests\Application;

use Aep\Application\Validation\ValidationOutcome;
use Aep\Application\Validation\ValidationPipeline;
use Aep\Application\Validation\ValidationRequest;
use Aep\Application\Validation\ValidationStep;
use Aep\Infrastructure\Validation\DeclarativeContextValidationStep;
use Tests\Support\Assert;

/**
 * ORCH-R5 validation pipeline coverage (updated for real execution evidence).
 */
final class ValidationPipelineTest
{
    public function test_all_pass(): void
    {
        $pipeline = new ValidationPipeline([
            new DeclarativeContextValidationStep(),
            new FixedValidationStep('always_pass', true, 'ok'),
        ]);

        $report = $pipeline->run(new ValidationRequest(
            'msn_val_1',
            '2026-07-30T12:00:00Z',
            $this->evidence(['src/Domain/Mission/Mission.php'])
        ));

        Assert::true($report->isPassed());
        Assert::same('passed', $report->outcome());
        Assert::same(2, count($report->outcomes()));
        Assert::same('all validation steps passed', $report->reason());
    }

    public function test_one_step_fail(): void
    {
        $pipeline = new ValidationPipeline([
            new DeclarativeContextValidationStep(),
            new FixedValidationStep('policy', false, 'policy violated'),
        ]);

        $report = $pipeline->run(new ValidationRequest(
            'msn_val_2',
            '2026-07-30T12:00:00Z',
            $this->evidence(['src/Domain/Mission/Mission.php'])
        ));

        Assert::true($report->isFailed());
        Assert::same('failed', $report->outcome());
        Assert::true(str_contains($report->reason(), 'policy: policy violated'));
    }

    public function test_declarative_context_failure(): void
    {
        $pipeline = new ValidationPipeline([new DeclarativeContextValidationStep()]);
        $report = $pipeline->run(new ValidationRequest(
            'msn_val_3',
            '2026-07-30T12:00:00Z',
            []
        ));

        Assert::true($report->isFailed());
        Assert::same('declarative_context: providerId is required for validation', $report->reason());
    }

    public function test_execution_failure_aborts(): void
    {
        $pipeline = new ValidationPipeline([
            new DeclarativeContextValidationStep(),
            new ThrowingValidationStep(),
        ]);

        Assert::throws(\RuntimeException::class, static function () use ($pipeline): void {
            $pipeline->run(new ValidationRequest(
                'msn_val_4',
                '2026-07-30T12:00:00Z',
                (new self())->evidence(['src/Domain/Mission/Mission.php'])
            ));
        });
    }

    public function test_configuration_failure_empty_steps(): void
    {
        Assert::throws(\InvalidArgumentException::class, static function (): void {
            new ValidationPipeline([]);
        });
    }

    public function test_configuration_failure_invalid_request(): void
    {
        Assert::throws(\InvalidArgumentException::class, static function (): void {
            new ValidationRequest('', '2026-07-30T12:00:00Z');
        });
    }

    public function test_report_generation_exposes_outcomes(): void
    {
        $pipeline = new ValidationPipeline([
            new FixedValidationStep('a', true, 'a-ok'),
            new FixedValidationStep('b', false, 'b-bad'),
            new FixedValidationStep('c', true, 'c-ok'),
        ]);

        $report = $pipeline->run(new ValidationRequest('msn_val_5', '2026-07-30T12:00:00Z'));
        Assert::same(3, count($report->outcomes()));
        Assert::true($report->isFailed());
        Assert::same('b: b-bad', $report->reason());
        Assert::same('a', $report->outcomes()[0]->stepId());
        Assert::same('b', $report->outcomes()[1]->stepId());
        Assert::same('c', $report->outcomes()[2]->stepId());
    }

    /**
     * @param list<string> $paths
     * @return array<string, mixed>
     */
    private function evidence(array $paths): array
    {
        return [
            'declaredPaths' => $paths,
            'providerId' => 'codex',
            'sessionId' => 'esess_val',
            'workspacePath' => '/tmp/ws_val',
            'filesChanged' => $paths,
            'patchId' => 'patch_val',
            'patchStatus' => 'ready',
            'mergeReady' => true,
            'artifacts' => ['diff' => 'a'],
        ];
    }
}

/**
 * Deterministic test double (not a production step).
 */
final class FixedValidationStep implements ValidationStep
{
    public function __construct(
        private string $id,
        private bool $pass,
        private string $message
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function run(ValidationRequest $request): ValidationOutcome
    {
        return $this->pass
            ? ValidationOutcome::passed($this->id, $this->message)
            : ValidationOutcome::failed($this->id, $this->message);
    }
}

final class ThrowingValidationStep implements ValidationStep
{
    public function id(): string
    {
        return 'throwing';
    }

    public function run(ValidationRequest $request): ValidationOutcome
    {
        throw new \RuntimeException('simulated step crash');
    }
}
