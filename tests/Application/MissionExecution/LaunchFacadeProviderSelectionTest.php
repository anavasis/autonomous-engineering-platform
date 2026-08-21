<?php

declare(strict_types=1);

namespace Tests\Application\MissionExecution;

use Aep\Application\Execution\ExecutionService;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionControl\Auth\Role;
use Aep\Application\MissionControl\Auth\User;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\MissionEngine\DefaultMissionPlanFactory;
use Aep\Application\MissionEngine\MissionEngine;
use Aep\Application\MissionExecution\Model\ExecutionPlan;
use Aep\Application\MissionExecution\Model\MissionIntake;
use Aep\Application\MissionExecution\Service\LaunchFacade;
use Aep\Application\Validation\ValidationPipeline;
use Aep\Infrastructure\EngineeringExecution\Store\JsonExecutionSettingsStore;
use Aep\Infrastructure\Execution\DeclarativeLocalExecutor;
use Aep\Infrastructure\MissionEngine\InMemoryMissionRunRepository;
use Aep\Infrastructure\Persistence\InMemoryMissionRepository;
use Aep\Infrastructure\Validation\DeclarativeContextValidationStep;
use Tests\Support\Assert;

final class LaunchFacadeProviderSelectionTest
{
    public function test_explicit_provider_id_is_persisted_into_run_checkpoint(): void
    {
        $root = sys_get_temp_dir() . '/aep_launch_prov_' . bin2hex(random_bytes(4));
        try {
            $runs = new InMemoryMissionRunRepository();
            $launch = $this->facade($root, $runs, null);
            $result = $launch->launch(
                $this->actor(),
                $this->intake('in_explicit'),
                $this->plan('in_explicit', ['providerId' => 'codex'])
            );
            $attrs = $runs->getCheckpoint($result['runId'])->attributes();
            Assert::same('codex', $attrs['providerId'] ?? null);
            Assert::true(($attrs['providerId'] ?? null) !== 'codex-cli');
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_settings_default_provider_id_codex_is_persisted(): void
    {
        $root = sys_get_temp_dir() . '/aep_launch_def_' . bin2hex(random_bytes(4));
        try {
            $settings = new JsonExecutionSettingsStore($root . '/execution');
            $settings->update(['defaultProviderId' => 'codex']);
            $runs = new InMemoryMissionRunRepository();
            $launch = $this->facade($root, $runs, $settings);
            $result = $launch->launch(
                $this->actor(),
                $this->intake('in_default'),
                $this->plan('in_default', [])
            );
            Assert::same('codex', $runs->getCheckpoint($result['runId'])->attributes()['providerId'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_null_provider_rejects_launch(): void
    {
        $root = sys_get_temp_dir() . '/aep_launch_rej_' . bin2hex(random_bytes(4));
        try {
            $settings = new JsonExecutionSettingsStore($root . '/execution');
            Assert::same(null, $settings->get()['defaultProviderId']);
            $launch = $this->facade($root, new InMemoryMissionRunRepository(), $settings);
            Assert::throws(\InvalidArgumentException::class, function () use ($launch): void {
                $launch->launch($this->actor(), $this->intake('in_none'), $this->plan('in_none', []));
            });
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_empty_provider_id_falls_through_to_settings_then_rejects(): void
    {
        $root = sys_get_temp_dir() . '/aep_launch_empty_' . bin2hex(random_bytes(4));
        try {
            $launch = $this->facade($root, new InMemoryMissionRunRepository(), new JsonExecutionSettingsStore($root . '/execution'));
            Assert::throws(\InvalidArgumentException::class, function () use ($launch): void {
                $launch->launch(
                    $this->actor(),
                    $this->intake('in_empty'),
                    $this->plan('in_empty', ['providerId' => '  '])
                );
            });
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * @param array<string, mixed> $launchAttributes
     */
    private function facade(string $root, InMemoryMissionRunRepository $runs, ?JsonExecutionSettingsStore $settings): LaunchFacade
    {
        $missionRepo = new InMemoryMissionRepository();
        $missions = new MissionCommandService($missionRepo);
        $engine = new MissionEngine(
            $missions,
            $missionRepo,
            $runs,
            new DefaultMissionPlanFactory(),
            new ExecutionService(new DeclarativeLocalExecutor()),
            new ValidationPipeline([new DeclarativeContextValidationStep()]),
        );

        return new LaunchFacade($missions, $engine, null, $settings);
    }

    private function actor(): User
    {
        return new User(
            'user_launch',
            'operator',
            'Operator',
            password_hash('x', PASSWORD_ARGON2ID),
            new Role(Role::ADMIN),
            '2026-08-05T00:00:00Z',
        );
    }

    private function intake(string $id): MissionIntake
    {
        $at = Utc::now();
        $intake = new MissionIntake(
            $id,
            'user_launch',
            'Create README',
            MissionIntake::STATUS_RECEIVED,
            'conv_' . $id,
            $at,
            $at,
        );
        $intake->markReady($at);

        return $intake;
    }

    /**
     * @param array<string, mixed> $launchAttributes
     */
    private function plan(string $intakeId, array $launchAttributes): ExecutionPlan
    {
        return new ExecutionPlan(
            'plan_' . $intakeId,
            $intakeId,
            'aep.default_mission',
            '1',
            'Create README',
            null,
            'github',
            'anavasis/aep-codex-smoke',
            ['allowedPaths' => ['README.md'], 'nonGoals' => [], 'branch' => 'main'],
            [],
            [],
            [],
            [],
            [['id' => 's1', 'name' => 'step']],
            60,
            ['whyWorkflow' => 'test'],
            [],
            $launchAttributes,
            [],
            Utc::now(),
        );
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
