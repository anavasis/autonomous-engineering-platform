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
use Aep\Application\MissionControl\Query\ApprovalQueryService;
use Aep\Application\MissionControl\Query\MissionQueryService;
use Aep\Application\MissionEngine\DefaultMissionPlanFactory;
use Aep\Application\MissionEngine\MissionEngine;
use Aep\Application\MissionEngine\MissionEngineRequest;
use Aep\Application\MissionEngine\MissionRunState;
use Aep\Application\MissionEngine\RetryPolicy;
use Aep\Application\MissionEngine\TimeoutPolicy;
use Aep\Application\Validation\ValidationPipeline;
use Aep\Domain\Mission\ValueObject\MissionState;
use Aep\Infrastructure\MissionControl\Catalog\JsonMissionCatalog;
use Aep\Infrastructure\MissionControl\Catalog\JsonRunCatalog;
use Aep\Infrastructure\MissionEngine\JsonFileMissionRunRepository;
use Aep\Infrastructure\Persistence\JsonFileMissionRepository;
use Aep\Infrastructure\Validation\DeclarativeContextValidationStep;
use Tests\Support\Assert;

final class RealProviderVerifiedPatchE2ETest
{
    public function test_deterministic_fake_provider_verified_patch_path(): void
    {
        $root = sys_get_temp_dir() . '/aep_e2e_patch_' . bin2hex(random_bytes(4));
        try {
            $missionsDir = $root . '/missions';
            $runsDir = $root . '/runs';
            mkdir($missionsDir, 0775, true);
            mkdir($runsDir, 0775, true);
            $missionRepo = new JsonFileMissionRepository($missionsDir);
            $runRepo = new JsonFileMissionRunRepository($runsDir);
            $missions = new MissionCommandService($missionRepo);
            $engine = new MissionEngine(
                $missions,
                $missionRepo,
                $runRepo,
                new DefaultMissionPlanFactory(),
                new ExecutionService($this->fakeProvider()),
                new ValidationPipeline([new DeclarativeContextValidationStep()]),
            );

            $missions->create(new CreateMission(
                'msn_e2e_1',
                'github',
                'anavasis/aep-codex-smoke',
                'Create README.md',
                'user',
                'tester',
                '2026-08-05T12:00:00Z'
            ));
            $missions->defineScope(new DefineScope('msn_e2e_1', ['README.md'], []));

            $waitingInspection = $engine->start(new MissionEngineRequest(
                'run_e2e_1',
                'msn_e2e_1',
                '2026-08-05T12:00:00Z',
                'user',
                'tester',
                [
                    'providerId' => 'codex',
                    'allowedPaths' => ['README.md'],
                ],
                null,
                new RetryPolicy(1, 0),
                TimeoutPolicy::disabled()
            ));
            Assert::same(MissionRunState::WAITING, $waitingInspection->engineState()->toString());
            Assert::same('inspection', $waitingInspection->currentStepId());
            Assert::same('codex', $runRepo->getCheckpoint('run_e2e_1')->attributes()['providerId'] ?? null);

            $approvals = new ApprovalQueryService(
                new JsonMissionCatalog($missionRepo, $missionsDir),
                new MissionQueryService(
                    new JsonMissionCatalog($missionRepo, $missionsDir),
                    new JsonRunCatalog($runRepo, $runsDir)
                )
            );
            $rows = array_values(array_filter(
                $approvals->list(),
                static fn (array $i): bool => ($i['missionId'] ?? null) === 'msn_e2e_1'
            ));
            Assert::same(1, count($rows));
            Assert::same('implementation_gate', $rows[0]['kind']);

            $waitingCommit = $engine->resume('run_e2e_1', [
                'gate.inspection' => 'approved',
            ], '2026-08-05T12:01:00Z');
            Assert::same(MissionRunState::WAITING, $waitingCommit->engineState()->toString());
            Assert::same('commit', $waitingCommit->currentStepId());

            $attrs = $runRepo->getCheckpoint('run_e2e_1')->attributes();
            Assert::same('esess_e2e', $attrs['sessionId'] ?? null);
            Assert::same('/tmp/ws_e2e', $attrs['workspacePath'] ?? null);
            Assert::same(['README.md'], $attrs['filesChanged'] ?? null);
            Assert::same('patch_e2e', $attrs['patchId'] ?? null);
            Assert::same('passed', $attrs['validationOutcome'] ?? null);
            Assert::true(!str_contains(json_encode($attrs) ?: '', 'declarative local'));

            $commitRows = array_values(array_filter(
                $approvals->list(),
                static fn (array $i): bool => ($i['missionId'] ?? null) === 'msn_e2e_1'
            ));
            Assert::same(1, count($commitRows));
            Assert::same('implementation_gate', $commitRows[0]['kind']);
            Assert::same('commit', $commitRows[0]['gateId'] ?? null);
            Assert::true(!in_array('merge', array_column($commitRows, 'kind'), true));

            $done = $engine->resume('run_e2e_1', [
                'gate.commit' => 'approved',
            ], '2026-08-05T12:02:00Z');
            Assert::same(MissionRunState::COMPLETED, $done->engineState()->toString());
            Assert::same(MissionState::COMPLETED, $done->missionState());
            $msgs = [];
            foreach ($runRepo->getTimeline('run_e2e_1')->entries() as $entry) {
                if ($entry->step() === 'complete_mission' && $entry->event() === 'step_finished') {
                    $msgs[] = $entry->message();
                }
                Assert::true(!str_contains(strtolower($entry->message()), 'declarative local'));
            }
            Assert::true(in_array('Mission completed with verified patch artifact.', $msgs, true));
            Assert::true(!str_contains(strtolower(implode(' ', $msgs)), 'pr created'));
            Assert::true(!str_contains(strtolower(implode(' ', $msgs)), 'remote'));
        } finally {
            $this->removeDir($root);
        }
    }

    private function fakeProvider(): Executor
    {
        return new class implements Executor {
            public function id(): string
            {
                return 'provider_routing';
            }

            public function execute(ExecutionRequest $request): ExecutionResult
            {
                Assert::same('codex', $request->contextValue('providerId'));

                return ExecutionResult::succeeded($this->id(), 'codex-simulated ok', [
                    'providerId' => 'codex',
                    'routedProviderId' => 'codex',
                    'actualExecutorId' => 'provider_routing',
                    'sessionId' => 'esess_e2e',
                    'workspacePath' => '/tmp/ws_e2e',
                    'filesChanged' => ['README.md'],
                    'artifacts' => ['diff' => 'art_e2e', 'log' => 'art_log'],
                    'usage' => ['durationSeconds' => 3.5],
                    'checkpointId' => 'cp_e2e',
                    'patchId' => 'patch_e2e',
                    'patchStatus' => 'ready',
                    'mergeReady' => true,
                ]);
            }
        };
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
