<?php

declare(strict_types=1);

namespace Tests\Application\Planning;

use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionEngine\DefaultMissionPlanFactory;
use Aep\Application\MissionEngine\MissionEngine;
use Aep\Application\Planning\Model\PlanningEvent;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Policy\SchedulingPolicyFactory;
use Aep\Application\Planning\Service\CriticalPathAnalyzer;
use Aep\Application\Planning\Service\DependencyManager;
use Aep\Application\Planning\Service\EstimateService;
use Aep\Application\Planning\Service\FailureRecoveryPolicy;
use Aep\Application\Planning\Service\PlanningPipelineService;
use Aep\Application\Planning\Service\ProgramPlanner;
use Aep\Application\Planning\Service\ProviderAllocator;
use Aep\Application\Planning\Service\Replanner;
use Aep\Application\Planning\Service\ResourceAllocator;
use Aep\Application\Planning\Service\Scheduler;
use Aep\Application\Planning\Service\WorkspaceAllocator;
use Aep\Application\Validation\ValidationPipeline;
use Aep\Infrastructure\Execution\DeclarativeLocalExecutor;
use Aep\Infrastructure\Persistence\JsonFileMissionRepository;
use Aep\Infrastructure\Planning\Adapter\PlanningLaunchAdapter;
use Aep\Infrastructure\Planning\Store\FilesystemProgramStore;
use Aep\Infrastructure\Planning\Store\JsonPlanningSettingsStore;
use Aep\Infrastructure\MissionEngine\JsonFileMissionRunRepository;
use Aep\Infrastructure\Validation\DeclarativeContextValidationStep;
use Aep\Application\Execution\ExecutionService;
use Tests\Support\Assert;

final class PlanningPipelineServiceTest
{
    public function test_create_plan_emits_events_and_fingerprints(): void
    {
        $root = sys_get_temp_dir() . '/aep_plan_' . bin2hex(random_bytes(4));
        try {
            $pipeline = $this->pipeline($root);
            $program = $pipeline->create([
                'title' => 'Demo Program',
                'objective' => 'Inspect scope; implement feature; validate and seal',
            ]);
            Assert::true(str_starts_with($program->programId(), 'prg_'));
            Assert::same(Program::STATUS_PLANNED, $program->status());
            Assert::same(1, $program->toArray()['schemaVersion'] ?? null);
            Assert::true(str_starts_with($program->reproducibilityFingerprint(), 'sha256:'));
            Assert::true(str_starts_with($program->integrityHash(), 'sha256:'));
            Assert::true(count($program->graph()->nodes()) >= 3);
            $types = array_map(static fn ($e) => $e->type(), $pipeline->events($program->programId()));
            Assert::true(in_array(PlanningEvent::PROGRAM_CREATED, $types, true));
            Assert::true(in_array(PlanningEvent::PROGRAM_PLANNED, $types, true));
            $cp = $program->criticalPath();
            Assert::true(isset($cp['nodeIds']));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_scheduler_is_policy_driven_and_launches_ready_nodes(): void
    {
        $root = sys_get_temp_dir() . '/aep_plan_' . bin2hex(random_bytes(4));
        try {
            $pipeline = $this->pipeline($root);
            $program = $pipeline->create([
                'title' => 'Sched',
                'objective' => 'only one phase objective with enough tokens here',
                'nodes' => [
                    ['title' => 'A', 'objective' => 'implement alpha module changes', 'priority' => 'high'],
                    ['title' => 'B', 'objective' => 'implement beta module changes', 'dependsOn' => [], 'priority' => 'normal'],
                ],
            ]);
            // Force two independent nodes
            $result = $pipeline->start($program->programId(), 'tester', [
                'executionSettings' => ['defaultProviderId' => 'local-agent', 'enabledProviderIds' => ['local-agent']],
                'workspaceSettings' => ['maxConcurrentWorkspaces' => 32],
            ]);
            Assert::true(isset($result['policyTrace']));
            Assert::true(is_array($result['policyTrace']));
            Assert::true(count($result['admitted']) >= 1);
            $updated = $pipeline->get($program->programId());
            Assert::true($updated !== null);
            $types = array_map(static fn ($e) => $e->type(), $pipeline->events($program->programId()));
            Assert::true(in_array(PlanningEvent::NODE_QUEUED, $types, true));
            Assert::true(in_array(PlanningEvent::NODE_STARTED, $types, true));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_pause_resume_and_replan_preview(): void
    {
        $root = sys_get_temp_dir() . '/aep_plan_' . bin2hex(random_bytes(4));
        try {
            $pipeline = $this->pipeline($root);
            $program = $pipeline->create([
                'title' => 'Replan',
                'objective' => 'phase one work; phase two work; phase three work',
            ]);
            $paused = $pipeline->pause($program->programId());
            Assert::same(Program::STATUS_PAUSED, $paused->status());
            $types = array_map(static fn ($e) => $e->type(), $pipeline->events($program->programId()));
            Assert::true(in_array(PlanningEvent::PROGRAM_PAUSED, $types, true));
            $preview = $pipeline->replanPreview($program->programId(), $program->graph()->nodes()[0]->nodeId());
            Assert::true(isset($preview['proposed']['generation']));
            Assert::true(($preview['proposed']['generation'] ?? 0) > ($preview['current']['generation'] ?? 0));
        } finally {
            $this->removeDir($root);
        }
    }

    private function pipeline(string $root): PlanningPipelineService
    {
        $missionsDir = $root . '/missions';
        $runsDir = $root . '/runs';
        mkdir($missionsDir, 0775, true);
        mkdir($runsDir, 0775, true);
        $missionRepo = new JsonFileMissionRepository($missionsDir);
        $runRepo = new JsonFileMissionRunRepository($runsDir);
        $missionCommands = new MissionCommandService($missionRepo);
        $engine = new MissionEngine(
            $missionCommands,
            $missionRepo,
            $runRepo,
            new DefaultMissionPlanFactory(),
            new ExecutionService(new DeclarativeLocalExecutor()),
            new ValidationPipeline([new DeclarativeContextValidationStep()])
        );

        $settings = new JsonPlanningSettingsStore($root . '/planning');
        $store = new FilesystemProgramStore($root . '/planning');
        $deps = new DependencyManager();
        $estimates = new EstimateService();
        $critical = new CriticalPathAnalyzer();
        $planner = new ProgramPlanner($deps, $estimates, $critical, $store);
        $replanner = new Replanner($store, $planner, $deps, $estimates, $critical);
        $scheduler = new Scheduler(
            $store,
            $settings,
            SchedulingPolicyFactory::fromSettings($settings->get()),
            $deps,
            new ResourceAllocator(),
            new ProviderAllocator(),
            new WorkspaceAllocator(),
            new FailureRecoveryPolicy(),
            new PlanningLaunchAdapter($missionCommands, $engine),
            $critical,
            $replanner,
        );

        return new PlanningPipelineService($store, $settings, $planner, $scheduler, $replanner, $deps, $critical);
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
            $path = $file->getPathname();
            $file->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
