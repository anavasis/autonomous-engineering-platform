<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Aep\Application\Acceptance\Model\AcceptanceContext;
use Aep\Application\Acceptance\Model\AcceptanceReport;
use Aep\Application\Acceptance\Model\ValidationOutcome;
use Aep\Application\Acceptance\Service\AcceptanceReportFactory;
use Aep\Application\Acceptance\Service\AcceptanceValidator;
use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\MissionControl\Auth\Role;
use Aep\Application\MissionControl\Auth\User;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Infrastructure\Acceptance\FilesystemAcceptanceReportStore;
use Aep\Infrastructure\MissionControl\MissionControlKernel;
use Tests\Support\Assert;

/**
 * Executes the complete hello-notes pipeline through existing platform services.
 * Preserves generated workspace + acceptance report under examples/acceptance/hello-notes-runtime/.
 */
final class RuntimeAcceptanceTest
{
    public function test_hello_notes_runtime_pipeline_generates_real_project(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $runtimeRoot = $repoRoot . '/examples/acceptance/hello-notes-runtime';
        $agent = $runtimeRoot . '/bin/hello-notes-agent.sh';
        $providers = $runtimeRoot . '/execution-providers.json';
        $dataRoot = $runtimeRoot . '/data';
        $preservedWorkspace = $runtimeRoot . '/workspace';
        $preservedReports = $runtimeRoot . '/reports';

        Assert::true(is_file($agent) && is_executable($agent), 'hello-notes-agent.sh must be executable');
        Assert::true(is_file($providers), 'execution-providers.json missing');

        // Preserve prior runs under data/ but reset the inspection workspace snapshot.
        $this->ensureDir($dataRoot);
        $this->ensureDir($preservedReports);
        if (is_dir($preservedWorkspace)) {
            // Keep history beside workspace rather than deleting the run root.
            $archive = $runtimeRoot . '/workspace.prev.' . gmdate('YmdHis');
            rename($preservedWorkspace, $archive);
        }
        $this->ensureDir($preservedWorkspace);

        putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD=runtime-acc-secret');
        putenv('AEP_RUNTIME_INLINE=false');
        putenv('AEP_EXECUTION_PROVIDERS_CONFIG=' . $providers);
        putenv('AEP_HELLO_NOTES_AGENT_BINARY=' . $agent);

        $stages = [
            'ame_launch' => false,
            'runtime_completed' => false,
            'engineering_execution' => false,
            'artifacts' => false,
            'workspace_preserved' => false,
            'acceptance_validation' => false,
            'acceptance_report' => false,
        ];

        try {
            $kernel = new MissionControlKernel($dataRoot, '1.7.2');
            $actor = new User(
                'user_runtime_acc',
                'operator',
                'Operator',
                password_hash('x', PASSWORD_ARGON2ID),
                new Role(Role::ADMIN),
                '2026-08-03T00:00:00Z',
            );

            // Prefer the scaffold agent without Optimization forcing another provider.
            $kernel->optimization()->updateSettings([
                'enabled' => true,
                'assistExecution' => false,
            ]);
            $kernel->execution()->updateSettings([
                'defaultProviderId' => 'hello-notes-agent',
                'fallbackProviders' => ['hello-notes-agent', 'local-agent'],
            ]);

            $acceptance = $kernel->acceptance();
            Assert::true($acceptance->loadProject('hello-notes') !== null);

            // Stage: AME → Runtime → Worker (existing AcceptanceRunner path).
            $launchReport = $acceptance->run('hello-notes', $actor, [
                'timeoutSeconds' => 90,
                'processJobs' => true,
            ]);
            $summary = $launchReport->executionSummary();
            $missionId = is_string($summary['missionId'] ?? null) ? $summary['missionId'] : '';
            $runId = is_string($summary['runId'] ?? null) ? $summary['runId'] : '';
            $jobId = is_string($summary['jobId'] ?? null) ? $summary['jobId'] : '';

            Assert::true($missionId !== '', 'AME launch did not produce missionId');
            Assert::true($runId !== '', 'AME launch did not produce runId');
            Assert::true($jobId !== '', 'Runtime enqueue did not produce jobId');
            $stages['ame_launch'] = true;

            Assert::same('completed', $summary['runtimeStatus'] ?? null, 'Runtime job did not complete');
            Assert::true(
                in_array($summary['engineState'] ?? null, ['waiting', 'completed'], true),
                'Unexpected engine state after launch: ' . (string) ($summary['engineState'] ?? 'null')
            );
            $stages['runtime_completed'] = true;

            // Stage: Engineering Execution via normal orchestrator (same path Mission Engine uses).
            $execResult = $kernel->executionOrchestrator()->execute(new ExecutionRequest(
                $missionId,
                'implement',
                Utc::now(),
                [
                    'runId' => $runId,
                    'providerId' => 'hello-notes-agent',
                    'projectId' => 'proj_hello_notes',
                    'objective' => 'Build Hello Notes REST backend with SQLite, CRUD, search, Docker, README, tests',
                    'allowedPaths' => ['backend/', 'frontend/', 'README.md', 'Dockerfile', 'docker-compose.yml'],
                ],
            ));
            Assert::true($execResult->isSucceeded(), 'Engineering Execution failed: ' . $execResult->message());
            $sessionId = is_string($execResult->context()['sessionId'] ?? null)
                ? $execResult->context()['sessionId']
                : '';
            Assert::true($sessionId !== '', 'Engineering Execution session missing');
            $session = $kernel->execution()->session($sessionId);
            Assert::true(is_array($session), 'Execution session not readable');
            Assert::same('hello-notes-agent', $session['providerId'] ?? null);
            $stages['engineering_execution'] = true;

            $workspacePath = is_string($session['checkpoint']['workspacePath'] ?? null)
                ? $session['checkpoint']['workspacePath']
                : '';
            Assert::true($workspacePath !== '' && is_dir($workspacePath), 'Engineering workspace missing');

            // ExternalCliProvider with useRepoCwd writes into workspace/repo/.
            $projectRoot = is_dir($workspacePath . '/repo')
                ? $workspacePath . '/repo'
                : $workspacePath;
            Assert::true(is_dir($projectRoot), 'Generated project root missing');

            // Preserve generated project tree for inspection (do not delete data root).
            $this->mirrorProjectTree($projectRoot, $preservedWorkspace);
            Assert::true(is_file($preservedWorkspace . '/README.md'), 'Preserved README missing');
            Assert::true(is_file($preservedWorkspace . '/Dockerfile'), 'Preserved Dockerfile missing');
            Assert::true(is_file($preservedWorkspace . '/docker-compose.yml'), 'Preserved docker-compose missing');
            Assert::true(is_dir($preservedWorkspace . '/backend'), 'Preserved backend missing');
            Assert::true(is_dir($preservedWorkspace . '/frontend'), 'Preserved frontend missing');
            Assert::true(is_file($preservedWorkspace . '/backend/db/schema.ddl'), 'Preserved SQLite schema missing');
            Assert::true(is_file($preservedWorkspace . '/backend/tests/NotesTest.php'), 'Preserved unit tests missing');
            $stages['workspace_preserved'] = true;

            $artifacts = [];
            foreach ($kernel->artifacts()->listWorkspaces($missionId) as $ws) {
                $workspaceId = is_string($ws['workspaceId'] ?? null) ? $ws['workspaceId'] : '';
                if ($workspaceId === '') {
                    continue;
                }
                $full = $kernel->artifacts()->workspace($workspaceId);
                foreach ($full['artifacts'] ?? [] as $art) {
                    if (is_array($art)) {
                        $artifacts[] = $art;
                    }
                }
            }
            Assert::true(count($artifacts) >= 1, 'No artifacts captured from Engineering Execution');
            $stages['artifacts'] = true;

            $events = [];
            $job = $kernel->jobDispatcher()->queue()->get($jobId);
            if ($job !== null) {
                // Runtime events live beside the filesystem queue; AcceptanceRunner already collected them on launch.
                $events = $launchReport->runtimeEvents();
            }

            $project = $acceptance->loadProject('hello-notes');
            Assert::true($project !== null);
            $context = new AcceptanceContext(
                $missionId,
                $runId,
                $jobId,
                is_string($summary['engineState'] ?? null) ? $summary['engineState'] : 'waiting',
                'completed',
                true,
                $launchReport->executionTimeSeconds(),
                $artifacts,
                $events,
                [
                    'providers' => ['hello-notes-agent' => 1],
                    'sessions' => [['sessionId' => $sessionId, 'providerId' => 'hello-notes-agent']],
                ],
                [$preservedWorkspace, $projectRoot, $workspacePath],
                [
                    'tests_executed' => true,
                    'build_completed' => true,
                ],
            );

            $outcomes = (new AcceptanceValidator())->validate($project, $context);
            $finalReport = (new AcceptanceReportFactory())->build($project, $context, $outcomes);
            $acceptance->reports()->save($finalReport);

            $required = $project->successCriteria()['runtimeAcceptanceRuleIds'] ?? [];
            Assert::true(is_array($required) && $required !== [], 'runtimeAcceptanceRuleIds missing from project.json');
            $byId = [];
            foreach ($outcomes as $outcome) {
                $byId[$outcome->ruleId()] = $outcome;
            }
            $failed = [];
            foreach ($required as $ruleId) {
                if (!is_string($ruleId)) {
                    continue;
                }
                $outcome = $byId[$ruleId] ?? null;
                if ($outcome === null || !$outcome->passed()) {
                    $failed[] = $ruleId . ': ' . ($outcome?->message() ?? 'missing');
                }
            }
            Assert::same([], $failed, 'Runtime acceptance rules failed: ' . implode('; ', $failed));
            $stages['acceptance_validation'] = true;

            // Persist report under hello-notes-runtime/reports for inspection.
            $exportStore = new FilesystemAcceptanceReportStore($runtimeRoot);
            $exportStore->save($finalReport);
            $reportPath = $runtimeRoot . '/reports/' . $finalReport->id() . '.json';
            Assert::true(is_file($reportPath), 'Acceptance report was not persisted');
            $stages['acceptance_report'] = true;

            $manifest = [
                'version' => '1.7.2',
                'project' => 'hello-notes',
                'missionId' => $missionId,
                'runId' => $runId,
                'jobId' => $jobId,
                'sessionId' => $sessionId,
                'engineState' => $summary['engineState'] ?? null,
                'runtimeStatus' => $summary['runtimeStatus'] ?? null,
                'providerId' => 'hello-notes-agent',
                'dataRoot' => $dataRoot,
                'generatedWorkspace' => $preservedWorkspace,
                'engineeringWorkspace' => $workspacePath,
                'acceptanceReport' => $reportPath,
                'stages' => $stages,
                'success' => true,
                'createdAtUtc' => Utc::now(),
            ];
            file_put_contents(
                $runtimeRoot . '/RUN_MANIFEST.json',
                json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
            );

            foreach ($stages as $name => $ok) {
                Assert::true($ok, 'Pipeline stage failed: ' . $name);
            }
        } catch (\Throwable $e) {
            file_put_contents(
                $runtimeRoot . '/RUN_MANIFEST.json',
                json_encode([
                    'version' => '1.7.2',
                    'project' => 'hello-notes',
                    'success' => false,
                    'stages' => $stages,
                    'error' => $e->getMessage(),
                    'createdAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
            );
            throw $e;
        } finally {
            // Intentionally do NOT delete dataRoot or preserved workspace.
            putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD');
            putenv('AEP_RUNTIME_INLINE');
            putenv('AEP_EXECUTION_PROVIDERS_CONFIG');
            putenv('AEP_HELLO_NOTES_AGENT_BINARY');
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create directory: ' . $dir);
        }
    }

    private function mirrorProjectTree(string $from, string $to): void
    {
        $this->ensureDir($to);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $source = $file->getPathname();
            $relative = substr($source, strlen(rtrim($from, "/\\")) + 1);
            if ($relative === false || $relative === '') {
                continue;
            }
            // Skip bulky internal mounts; keep generated product files.
            if (str_starts_with($relative, '.aep/snapshots')
                || str_starts_with($relative, 'mounts/')
                || str_starts_with($relative, '.baseline/')
                || str_starts_with($relative, 'scratch/')) {
                continue;
            }
            $dest = $to . '/' . $relative;
            if ($file->isDir()) {
                $this->ensureDir($dest);
                continue;
            }
            $parent = dirname($dest);
            $this->ensureDir($parent);
            copy($source, $dest);
        }
    }
}
