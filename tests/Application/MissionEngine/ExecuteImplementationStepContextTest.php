<?php

declare(strict_types=1);

namespace Tests\Application\MissionEngine;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\ExecutionService;
use Aep\Application\Execution\Executor;
use Aep\Application\Mission\Command\CreateMission;
use Aep\Application\Mission\Command\DefineScope;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionEngine\CancellationToken;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\Step\ExecuteImplementationStep;
use Aep\Infrastructure\Persistence\InMemoryMissionRepository;
use Tests\Support\Assert;

final class ExecuteImplementationStepContextTest
{
    public function test_forwards_target_provider_repository_provider_id_and_allowed_paths(): void
    {
        $captured = null;
        $executor = new class ($captured) implements Executor {
            /** @param mixed $captured */
            public function __construct(private mixed &$captured)
            {
            }

            public function id(): string
            {
                return 'capture';
            }

            public function execute(ExecutionRequest $request): ExecutionResult
            {
                $this->captured = $request;

                return ExecutionResult::succeeded($this->id(), 'ok');
            }
        };

        $missions = new MissionCommandService(new InMemoryMissionRepository());
        $missions->create(new CreateMission(
            'msn_ctx_1',
            'github',
            'anavasis/aep-codex-smoke',
            'smoke',
            'user',
            'tester',
            '2026-08-03T12:00:00Z'
        ));
        $missions->defineScope(new DefineScope('msn_ctx_1', ['README.md'], []));

        $step = new ExecuteImplementationStep();
        $result = $step->execute(new MissionContext(
            'run_ctx_1',
            'msn_ctx_1',
            '2026-08-03T12:00:00Z',
            'user',
            'tester',
            $missions,
            new CancellationToken(),
            [
                'providerId' => 'codex',
                'allowedPaths' => ['README.md'],
            ],
            null,
            new ExecutionService($executor),
        ));

        Assert::true($result->isSucceeded());
        Assert::true($captured instanceof ExecutionRequest);
        /** @var ExecutionRequest $captured */
        Assert::same('run_ctx_1', $captured->contextValue('runId'));
        Assert::same('msn_ctx_1', $captured->contextValue('missionId'));
        Assert::same('codex', $captured->contextValue('providerId'));
        Assert::same(['README.md'], $captured->contextValue('allowedPaths'));
        $git = $captured->contextValue('git');
        Assert::true(is_array($git));
        Assert::same('github', $git['provider'] ?? null);
        Assert::same('anavasis/aep-codex-smoke', $git['repository'] ?? null);
        Assert::true(!array_key_exists('baseBranch', $git));
    }

    public function test_forwards_project_id_and_explicit_base_branch_only_when_supplied(): void
    {
        $captured = null;
        $executor = new class ($captured) implements Executor {
            /** @param mixed $captured */
            public function __construct(private mixed &$captured)
            {
            }

            public function id(): string
            {
                return 'capture';
            }

            public function execute(ExecutionRequest $request): ExecutionResult
            {
                $this->captured = $request;

                return ExecutionResult::succeeded($this->id(), 'ok');
            }
        };

        $missions = new MissionCommandService(new InMemoryMissionRepository());
        $missions->create(new CreateMission(
            'msn_ctx_2',
            'github',
            'owner/repo',
            'obj',
            'user',
            'tester',
            '2026-08-03T12:00:00Z'
        ));

        $step = new ExecuteImplementationStep();
        $step->execute(new MissionContext(
            'run_ctx_2',
            'msn_ctx_2',
            '2026-08-03T12:00:00Z',
            'user',
            'tester',
            $missions,
            new CancellationToken(),
            [
                'providerId' => 'codex',
                'git' => [
                    'provider' => 'github',
                    'repository' => 'owner/repo',
                    'baseBranch' => 'develop',
                ],
            ],
            'proj_bound_1',
            new ExecutionService($executor),
        ));

        Assert::true($captured instanceof ExecutionRequest);
        /** @var ExecutionRequest $captured */
        Assert::same('proj_bound_1', $captured->contextValue('projectId'));
        $git = $captured->contextValue('git');
        Assert::true(is_array($git));
        Assert::same('develop', $git['baseBranch'] ?? null);
    }

    public function test_allowed_paths_fall_back_to_mission_scope_when_attribute_absent(): void
    {
        $captured = null;
        $executor = new class ($captured) implements Executor {
            /** @param mixed $captured */
            public function __construct(private mixed &$captured)
            {
            }

            public function id(): string
            {
                return 'capture';
            }

            public function execute(ExecutionRequest $request): ExecutionResult
            {
                $this->captured = $request;

                return ExecutionResult::succeeded($this->id(), 'ok');
            }
        };

        $missions = new MissionCommandService(new InMemoryMissionRepository());
        $missions->create(new CreateMission(
            'msn_ctx_3',
            'github',
            'acme/widgets',
            'obj',
            'user',
            'tester',
            '2026-08-03T12:00:00Z'
        ));
        $missions->defineScope(new DefineScope('msn_ctx_3', ['docs/', 'src/'], ['secrets']));

        $step = new ExecuteImplementationStep();
        $step->execute(new MissionContext(
            'run_ctx_3',
            'msn_ctx_3',
            '2026-08-03T12:00:00Z',
            'user',
            'tester',
            $missions,
            new CancellationToken(),
            ['providerId' => 'codex'],
            null,
            new ExecutionService($executor),
        ));

        Assert::true($captured instanceof ExecutionRequest);
        /** @var ExecutionRequest $captured */
        Assert::same(['docs/', 'src/'], $captured->contextValue('allowedPaths'));
    }
}
