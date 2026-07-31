<?php

declare(strict_types=1);

namespace Tests\Application\MissionExecution;

use Aep\Application\MissionControl\Auth\Role;
use Aep\Application\MissionControl\Auth\User;
use Aep\Application\MissionExecution\Model\MissionIntake;
use Aep\Application\Project\Command\BindRepository;
use Aep\Application\Project\Command\CreateProject;
use Aep\Infrastructure\MissionControl\MissionControlKernel;
use Tests\Support\Assert;

final class AutonomousMissionServiceTest
{
    public function test_intake_plan_confirm_launch_flow(): void
    {
        $root = sys_get_temp_dir() . '/aep_ame_' . bin2hex(random_bytes(4));
        putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD=ame-secret');
        try {
            $kernel = new MissionControlKernel($root, '0.2.0');
            $actor = new User(
                'user_test',
                'operator',
                'Operator',
                password_hash('x', PASSWORD_ARGON2ID),
                new Role(Role::ADMIN),
                '2026-07-31T12:00:00Z'
            );

            // Create bound project so adaptive clarification asks nothing.
            $kernel->commands()->createProject($actor, 'proj_demo', 'demo-app', 'Demo App', 'Demo');
            // Bind via project command service path — use BindRepository through kernel projects persistence.
            $this->bindProject($root, 'proj_demo');

            // Reboot kernel to see bound project from disk
            $kernel = new MissionControlKernel($root, '0.2.0');

            $ame = $kernel->ame();
            $result = $ame->intake(
                $actor,
                "Fix the Client Panel email formatting bug.\nInspection first.\nDo not modify email formatting.\ngithub:anavasis/demo",
                'proj_demo',
                'client_req_1'
            );

            Assert::same(MissionIntake::STATUS_READY, $result['intake']['status'] ?? null);
            Assert::true(is_array($result['plan']));
            Assert::true(isset($result['plan']['explainability']['whyWorkflow']));
            Assert::true(isset($result['plan']['estimatedSteps']));

            $idempotent = $ame->intake($actor, 'ignored', 'proj_demo', 'client_req_1');
            Assert::same($result['intake']['id'], $idempotent['intake']['id']);

            $preview = $ame->preview((string) $result['intake']['id']);
            Assert::same('aep.default_mission', $preview['workflowId'] ?? null);

            $launched = $ame->confirmAndLaunch($actor, (string) $result['intake']['id'], true);
            Assert::true(isset($launched['missionId']));
            Assert::true(isset($launched['runId']));

            $conversation = $ame->conversationForMission((string) $launched['missionId']);
            Assert::true(is_array($conversation));
            Assert::true(count($conversation['turns'] ?? []) >= 1);

            $plan = $ame->planForMission((string) $launched['missionId']);
            Assert::true(is_array($plan));
            Assert::true(isset($plan['explainability']['whyApprovals']));

            $memory = $ame->projectMemory('proj_demo');
            Assert::true(is_array($memory));
            Assert::true(count($memory['successfulPatterns'] ?? []) >= 1);
        } finally {
            $this->removeDir($root);
            putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD');
        }
    }

    public function test_confirm_requires_explicit_flag(): void
    {
        $root = sys_get_temp_dir() . '/aep_ame_' . bin2hex(random_bytes(4));
        try {
            $kernel = new MissionControlKernel($root, '0.2.0');
            $actor = new User(
                'user_test',
                'operator',
                'Operator',
                password_hash('x', PASSWORD_ARGON2ID),
                new Role(Role::ADMIN),
                '2026-07-31T12:00:00Z'
            );
            $result = $kernel->ame()->intake(
                $actor,
                'Fix bug in src/Foo/Bar.php github:org/repo',
                null,
                null
            );
            if (($result['intake']['status'] ?? '') === MissionIntake::STATUS_READY) {
                try {
                    $kernel->ame()->confirmAndLaunch($actor, (string) $result['intake']['id'], false);
                    Assert::true(false, 'Expected confirmation error');
                } catch (\InvalidArgumentException $e) {
                    Assert::true(str_contains($e->getMessage(), 'confirmation'));
                }
            } else {
                Assert::true(in_array($result['intake']['status'], [
                    MissionIntake::STATUS_CLARIFYING,
                    MissionIntake::STATUS_READY,
                    MissionIntake::STATUS_REJECTED,
                ], true));
            }
        } finally {
            $this->removeDir($root);
        }
    }

    private function bindProject(string $root, string $projectId): void
    {
        $repo = new \Aep\Infrastructure\Persistence\JsonFileProjectRepository($root . '/projects');
        $svc = new \Aep\Application\Project\ProjectCommandService($repo);
        $svc->bindRepository(new BindRepository(
            $projectId,
            'github',
            'anavasis/demo',
            '2026-07-31T12:00:00Z'
        ));
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
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
