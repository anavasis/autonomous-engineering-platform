<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Aep\Application\Acceptance\Model\AcceptanceContext;
use Aep\Application\Acceptance\Model\AcceptanceProject;
use Aep\Application\Acceptance\Model\ValidationRule;
use Aep\Application\Acceptance\Service\AcceptanceReportFactory;
use Aep\Application\Acceptance\Service\AcceptanceValidator;
use Aep\Application\MissionControl\Auth\Role;
use Aep\Application\MissionControl\Auth\User;
use Aep\Infrastructure\Acceptance\FilesystemAcceptanceProjectRepository;
use Aep\Infrastructure\Acceptance\FilesystemAcceptanceReportStore;
use Aep\Infrastructure\MissionControl\MissionControlKernel;
use Tests\Support\Assert;

final class AcceptanceFrameworkTest
{
    public function test_hello_notes_loads_as_pluggable_data(): void
    {
        $repo = new FilesystemAcceptanceProjectRepository(
            dirname(__DIR__, 2) . '/examples/acceptance'
        );
        $project = $repo->get('hello-notes');
        Assert::true($project instanceof AcceptanceProject);
        Assert::same('hello-notes', $project->name());
        Assert::true(count($project->requirements()) >= 8);
        Assert::true(count($project->validationRules()) >= 8);
        Assert::true($this->requirementsContain($project, 'Docker'));
        Assert::true($this->requirementsContain($project, 'Backend'));
        Assert::true($this->requirementsContain($project, 'Frontend'));
        Assert::true($this->requirementsContain($project, 'SQLite'));
        Assert::true($this->requirementsContain($project, 'CRUD'));
        Assert::true($this->requirementsContain($project, 'Search'));
        Assert::true($this->requirementsContain($project, 'README'));
        Assert::true($this->requirementsContain($project, 'test'));
        Assert::true($repo->projectDirectory('hello-notes') !== null);
    }

    public function test_validator_passes_hello_notes_reference_workspace(): void
    {
        $repo = new FilesystemAcceptanceProjectRepository(
            dirname(__DIR__, 2) . '/examples/acceptance'
        );
        $project = $repo->get('hello-notes');
        Assert::true($project !== null);
        $workspace = $repo->projectDirectory('hello-notes') . '/workspace';

        $context = new AcceptanceContext(
            'msn_demo',
            'run_demo',
            'job_demo',
            'waiting',
            'completed',
            true,
            12.5,
            [['name' => 'README.md', 'kind' => 'file']],
            [['type' => 'Completed']],
            ['providers' => ['local-agent' => 1]],
            [$workspace],
            ['tests_executed' => true, 'build_completed' => true],
        );

        $outcomes = (new AcceptanceValidator())->validate($project, $context);
        $failed = array_values(array_filter($outcomes, static fn ($o) => !$o->passed()));
        Assert::same([], array_map(static fn ($o) => $o->ruleId() . ': ' . $o->message(), $failed));

        $report = (new AcceptanceReportFactory())->build($project, $context, $outcomes);
        Assert::true($report->success());
        Assert::true($report->recommendation() !== '');
    }

    public function test_future_project_is_pluggable_without_framework_changes(): void
    {
        $root = sys_get_temp_dir() . '/aep_acc_plug_' . bin2hex(random_bytes(4));
        mkdir($root . '/tiny-app', 0775, true);
        file_put_contents($root . '/tiny-app/project.json', json_encode([
            'name' => 'tiny-app',
            'description' => 'Pluggable sample',
            'requirements' => ['README'],
            'expectedArtifacts' => ['README.md'],
            'validationRules' => [
                [
                    'id' => 'readme',
                    'type' => ValidationRule::TYPE_FILE_EXISTS,
                    'params' => ['path' => 'README.md'],
                ],
            ],
            'successCriteria' => ['requiredRuleIds' => ['readme']],
            'execution' => ['referenceWorkspace' => 'workspace'],
        ], JSON_THROW_ON_ERROR));
        mkdir($root . '/tiny-app/workspace', 0775, true);
        file_put_contents($root . '/tiny-app/workspace/README.md', '# Tiny');

        try {
            $repo = new FilesystemAcceptanceProjectRepository($root);
            $names = array_map(static fn (AcceptanceProject $p) => $p->name(), $repo->all());
            Assert::true(in_array('tiny-app', $names, true));

            $project = $repo->get('tiny-app');
            Assert::true($project !== null);
            $context = new AcceptanceContext(
                null,
                null,
                null,
                null,
                null,
                false,
                0.1,
                [],
                [],
                [],
                [$root . '/tiny-app/workspace'],
            );
            $outcomes = (new AcceptanceValidator())->validate($project, $context);
            Assert::same(1, count($outcomes));
            Assert::true($outcomes[0]->passed());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_runner_reuses_ame_runtime_and_persists_report(): void
    {
        $root = sys_get_temp_dir() . '/aep_acc_run_' . bin2hex(random_bytes(4));
        putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD=acc-secret');
        putenv('AEP_RUNTIME_INLINE=false');
        try {
            $kernel = new MissionControlKernel($root, '1.7.1');
            $actor = new User(
                'user_acc',
                'operator',
                'Operator',
                password_hash('x', PASSWORD_ARGON2ID),
                new Role(Role::ADMIN),
                '2026-08-03T00:00:00Z',
            );

            $acceptance = $kernel->acceptance();
            Assert::true($acceptance->loadProject('hello-notes') !== null);

            $report = $acceptance->run('hello-notes', $actor, [
                'timeoutSeconds' => 60,
                'processJobs' => true,
            ]);

            Assert::same('hello-notes', $report->projectName());
            Assert::true($report->id() !== '');
            Assert::true(isset($report->executionSummary()['missionId']));
            Assert::true(isset($report->executionSummary()['runId']));
            Assert::true(isset($report->executionSummary()['jobId']));
            Assert::same('completed', $report->executionSummary()['runtimeStatus'] ?? null);
            Assert::true(in_array($report->executionSummary()['engineState'] ?? null, ['waiting', 'completed'], true));
            Assert::true($report->executionTimeSeconds() > 0);
            Assert::true(count($report->runtimeEvents()) >= 1);
            Assert::true($report->recommendation() !== '');
            Assert::true($report->success());

            $store = new FilesystemAcceptanceReportStore($root . '/acceptance');
            $loaded = $store->get($report->id());
            Assert::true($loaded !== null);
            Assert::same($report->id(), $loaded->id());
            Assert::true(is_file($root . '/acceptance/reports/' . $report->id() . '.json'));
        } finally {
            $this->removeDir($root);
            putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD');
            putenv('AEP_RUNTIME_INLINE');
        }
    }

    public function test_report_store_lists_by_project(): void
    {
        $root = sys_get_temp_dir() . '/aep_acc_store_' . bin2hex(random_bytes(4));
        try {
            $store = new FilesystemAcceptanceReportStore($root);
            $repo = new FilesystemAcceptanceProjectRepository(
                dirname(__DIR__, 2) . '/examples/acceptance'
            );
            $project = $repo->get('hello-notes');
            Assert::true($project !== null);
            $workspace = $repo->projectDirectory('hello-notes') . '/workspace';
            $context = new AcceptanceContext(
                'msn_x',
                'run_x',
                'job_x',
                'waiting',
                'completed',
                true,
                1.0,
                [],
                [],
                [],
                [$workspace],
                ['tests_executed' => true, 'build_completed' => true],
            );
            $outcomes = (new AcceptanceValidator())->validate($project, $context);
            $report = (new AcceptanceReportFactory())->build($project, $context, $outcomes);
            $store->save($report);
            $listed = $store->list('hello-notes');
            Assert::same(1, count($listed));
            Assert::same($report->id(), $listed[0]->id());
        } finally {
            $this->removeDir($root);
        }
    }

    private function requirementsContain(AcceptanceProject $project, string $needle): bool
    {
        $needle = strtolower($needle);
        foreach ($project->requirements() as $req) {
            if (str_contains(strtolower($req), $needle)) {
                return true;
            }
        }

        return false;
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
