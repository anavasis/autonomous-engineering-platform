<?php

declare(strict_types=1);

namespace Aep\Application\Acceptance\Service;

use Aep\Application\Acceptance\Model\AcceptanceContext;
use Aep\Application\Acceptance\Model\AcceptanceProject;
use Aep\Application\Acceptance\Model\AcceptanceReport;
use Aep\Application\Acceptance\Port\AcceptanceProjectRepository;
use Aep\Application\Acceptance\Port\AcceptanceReportStore;
use Aep\Application\EngineeringExecution\Service\EngineeringExecutionQueryService;
use Aep\Application\ExecutionRuntime\Port\JobQueue;
use Aep\Application\ExecutionRuntime\Port\RuntimeEventStore;
use Aep\Application\ExecutionRuntime\Service\JobDispatcher;
use Aep\Application\ExecutionRuntime\Service\RuntimeWorker;
use Aep\Application\MissionControl\Auth\User;
use Aep\Application\MissionControl\Command\MissionControlCommandFacade;
use Aep\Application\MissionControl\Query\ArtifactQueryService;
use Aep\Application\MissionControl\Query\MissionQueryService;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\MissionExecution\Model\MissionIntake;
use Aep\Application\MissionExecution\Service\AutonomousMissionService;
use Aep\Application\Project\Command\BindRepository;
use Aep\Application\Project\ProjectCommandService;

/**
 * Loads an AcceptanceProject, executes via existing AME → Runtime path,
 * monitors completion, collects artifacts/events, evaluates, and persists a report.
 */
final class AcceptanceRunner
{
    public function __construct(
        private readonly AcceptanceProjectRepository $projects,
        private readonly AcceptanceReportStore $reports,
        private readonly AcceptanceValidator $validator,
        private readonly AcceptanceReportFactory $reportFactory,
        private readonly AutonomousMissionService $ame,
        private readonly MissionControlCommandFacade $commands,
        private readonly ProjectCommandService $projectCommands,
        private readonly MissionQueryService $missions,
        private readonly ArtifactQueryService $artifacts,
        private readonly JobDispatcher $dispatcher,
        private readonly RuntimeWorker $worker,
        private readonly RuntimeEventStore $runtimeEvents,
        private readonly ?EngineeringExecutionQueryService $executionQuery = null,
        private readonly string $dataRoot = '',
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *   - timeoutSeconds: int
     *   - processJobs: bool (default true) drain RuntimeWorker while monitoring
     *   - actor: unused (passed explicitly)
     *   - evaluationWorkspace: string|null override workspace root
     *   - skipLaunch: bool evaluate-only against options.context override
     */
    public function run(string $projectName, User $actor, array $options = []): AcceptanceReport
    {
        $started = microtime(true);
        $project = $this->projects->get($projectName);
        if ($project === null) {
            throw new \InvalidArgumentException('Unknown acceptance project: ' . $projectName);
        }

        $execution = $project->execution();
        $this->ensureTargetProject($actor, $execution);

        $launch = $this->launchViaAme($actor, $project, $execution);
        $missionId = is_string($launch['missionId'] ?? null) ? $launch['missionId'] : null;
        $runId = is_string($launch['runId'] ?? null) ? $launch['runId'] : null;
        $jobId = is_string($launch['jobId'] ?? null) ? $launch['jobId'] : null;

        $timeout = is_int($options['timeoutSeconds'] ?? null)
            ? max(1, $options['timeoutSeconds'])
            : (is_int($execution['timeoutSeconds'] ?? null) ? max(1, $execution['timeoutSeconds']) : 120);
        $processJobs = ($options['processJobs'] ?? true) === true;

        $this->monitor($jobId, $runId, $timeout, $processJobs);

        $context = $this->collectContext(
            $project,
            $missionId,
            $runId,
            $jobId,
            microtime(true) - $started,
            $options,
        );

        $outcomes = $this->validator->validate($project, $context);
        $report = $this->reportFactory->build($project, $context, $outcomes);
        $this->reports->save($report);

        return $report;
    }

    /**
     * Evaluate a loaded project against an explicit context (no launch).
     * Useful for offline validation of workspace fixtures.
     *
     * @param array<string, mixed> $options
     */
    public function evaluateOnly(
        string $projectName,
        AcceptanceContext $context,
        array $options = [],
    ): AcceptanceReport {
        unset($options);
        $project = $this->projects->get($projectName);
        if ($project === null) {
            throw new \InvalidArgumentException('Unknown acceptance project: ' . $projectName);
        }
        $outcomes = $this->validator->validate($project, $context);
        $report = $this->reportFactory->build($project, $context, $outcomes);
        $this->reports->save($report);

        return $report;
    }

    public function loadProject(string $name): ?AcceptanceProject
    {
        return $this->projects->get($name);
    }

    /** @return list<AcceptanceProject> */
    public function listProjects(): array
    {
        return $this->projects->all();
    }

    public function reports(): AcceptanceReportStore
    {
        return $this->reports;
    }

    /**
     * @param array<string, mixed> $execution
     */
    private function ensureTargetProject(User $actor, array $execution): void
    {
        $projectId = is_string($execution['projectId'] ?? null) ? $execution['projectId'] : null;
        if ($projectId === null || $projectId === '') {
            return;
        }
        $slug = is_string($execution['projectSlug'] ?? null) ? $execution['projectSlug'] : $projectId;
        $display = is_string($execution['projectDisplayName'] ?? null)
            ? $execution['projectDisplayName']
            : $slug;

        try {
            $this->commands->createProject($actor, $projectId, $slug, $display, 'Acceptance target');
        } catch (\Throwable) {
            // Already exists — continue.
        }

        $provider = is_string($execution['provider'] ?? null) ? $execution['provider'] : 'github';
        $repository = is_string($execution['repository'] ?? null) ? $execution['repository'] : null;
        if ($repository === null || $repository === '') {
            return;
        }
        try {
            $this->projectCommands->bindRepository(new BindRepository(
                $projectId,
                $provider,
                $repository,
                Utc::now(),
            ));
        } catch (\Throwable) {
            // Already bound — continue.
        }
    }

    /**
     * @param array<string, mixed> $execution
     * @return array<string, mixed>
     */
    private function launchViaAme(User $actor, AcceptanceProject $project, array $execution): array
    {
        $prompt = $project->requirementsPrompt();
        $repo = is_string($execution['repository'] ?? null) ? $execution['repository'] : null;
        $provider = is_string($execution['provider'] ?? null) ? $execution['provider'] : 'github';
        if ($repo !== null && $repo !== '' && !str_contains($prompt, $provider . ':')) {
            $prompt .= "\n" . $provider . ':' . $repo;
        }

        $projectId = is_string($execution['projectId'] ?? null) ? $execution['projectId'] : null;
        $clientRequestId = 'acceptance_' . $project->name() . '_' . bin2hex(random_bytes(4));

        $intake = $this->ame->intake($actor, $prompt, $projectId, $clientRequestId);
        $intakeId = is_string($intake['intake']['id'] ?? null) ? $intake['intake']['id'] : '';
        if ($intakeId === '') {
            throw new \RuntimeException('Acceptance intake failed to produce intake id.');
        }

        $status = is_string($intake['intake']['status'] ?? null) ? $intake['intake']['status'] : '';
        if ($status === MissionIntake::STATUS_CLARIFYING) {
            $answers = is_array($execution['clarifyAnswers'] ?? null) ? $execution['clarifyAnswers'] : [];
            if ($answers !== []) {
                $intake = $this->ame->clarify($actor, $intakeId, $answers);
                $status = is_string($intake['intake']['status'] ?? null) ? $intake['intake']['status'] : '';
            }
        }

        if ($status !== MissionIntake::STATUS_READY && $status !== MissionIntake::STATUS_LAUNCHED) {
            throw new \RuntimeException(
                'Acceptance intake not ready for launch. Status: ' . $status
            );
        }

        if ($status === MissionIntake::STATUS_READY) {
            $this->ame->preview($intakeId);
        }

        return $this->ame->confirmAndLaunch($actor, $intakeId, true);
    }

    private function monitor(?string $jobId, ?string $runId, int $timeoutSeconds, bool $processJobs): void
    {
        $deadline = time() + $timeoutSeconds;
        $queue = $this->dispatcher->queue();
        $workerId = 'acceptance-' . getmypid();

        while (time() <= $deadline) {
            if ($processJobs) {
                $this->worker->processAvailable($workerId, 3);
            }

            $job = $this->resolveJob($queue, $jobId, $runId);
            if ($job !== null && $job->isTerminal()) {
                return;
            }

            // If no runtime job (sync fallback), stop once mission run exists.
            if ($job === null && $runId !== null) {
                $missionId = null;
                // best-effort: wait briefly then exit
                usleep(50000);

                return;
            }

            usleep(100000);
        }
    }

    private function resolveJob(JobQueue $queue, ?string $jobId, ?string $runId): ?\Aep\Application\ExecutionRuntime\Model\RuntimeJob
    {
        if ($jobId !== null && $jobId !== '') {
            $job = $queue->get($jobId);
            if ($job !== null) {
                return $job;
            }
        }
        if ($runId !== null && $runId !== '') {
            return $queue->findByRunId($runId);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function collectContext(
        AcceptanceProject $project,
        ?string $missionId,
        ?string $runId,
        ?string $jobId,
        float $elapsed,
        array $options,
    ): AcceptanceContext {
        $queue = $this->dispatcher->queue();
        $job = $this->resolveJob($queue, $jobId, $runId);
        if ($job !== null) {
            $jobId = $job->id();
            $runId = $runId ?? (is_string($job->payload()['runId'] ?? null) ? $job->payload()['runId'] : null);
            $missionId = $missionId ?? (is_string($job->payload()['missionId'] ?? null) ? $job->payload()['missionId'] : null);
        }

        $engineState = null;
        if ($missionId !== null) {
            $detail = $this->missions->get($missionId);
            $engineState = is_string($detail['latestRun']['engineState'] ?? null)
                ? $detail['latestRun']['engineState']
                : (is_string($detail['engineState'] ?? null) ? $detail['engineState'] : null);
            $run = $this->missions->latestRun($missionId);
            if ($run !== null) {
                $engineState = $run->engineState();
                $runId = $runId ?? $run->runId();
            }
        }

        $artifacts = [];
        if ($missionId !== null) {
            foreach ($this->artifacts->listWorkspaces($missionId) as $ws) {
                $workspaceId = is_string($ws['workspaceId'] ?? null) ? $ws['workspaceId'] : '';
                if ($workspaceId === '') {
                    continue;
                }
                $full = $this->artifacts->workspace($workspaceId);
                foreach ($full['artifacts'] ?? [] as $art) {
                    if (is_array($art)) {
                        $artifacts[] = $art;
                    }
                }
            }
        }

        $events = [];
        if ($jobId !== null && $jobId !== '') {
            foreach ($this->runtimeEvents->forJob($jobId) as $event) {
                $events[] = $event->toArray();
            }
        }

        $providerUsage = $this->collectProviderUsage($missionId);
        $roots = $this->workspaceRoots($project, $options, $missionId);
        $signals = $this->deriveSignals($roots, $artifacts, $events);

        return new AcceptanceContext(
            $missionId,
            $runId,
            $jobId,
            $engineState,
            $job?->status(),
            $missionId !== null && $runId !== null,
            $elapsed,
            $artifacts,
            $events,
            $providerUsage,
            $roots,
            $signals,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function collectProviderUsage(?string $missionId): array
    {
        if ($missionId === null || $this->executionQuery === null) {
            return [];
        }
        try {
            $session = $this->executionQuery->sessionForMission($missionId);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($session)) {
            return [];
        }
        $providerId = is_string($session['providerId'] ?? null) ? $session['providerId'] : null;
        $usageMetrics = is_array($session['usage'] ?? null) ? $session['usage'] : [];

        return [
            'sessions' => [[
                'sessionId' => $session['sessionId'] ?? null,
                'providerId' => $providerId,
                'status' => $session['status'] ?? null,
            ]],
            'providers' => $providerId !== null ? [$providerId => 1] : [],
            'usage' => $usageMetrics,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private function workspaceRoots(AcceptanceProject $project, array $options, ?string $missionId): array
    {
        $roots = [];
        if (is_string($options['evaluationWorkspace'] ?? null) && is_dir($options['evaluationWorkspace'])) {
            $roots[] = $options['evaluationWorkspace'];
        }

        $ref = $project->execution()['referenceWorkspace'] ?? null;
        if (is_string($ref) && $ref !== '') {
            $dir = $this->projects->projectDirectory($project->name());
            if ($dir !== null) {
                $candidate = $dir . '/' . ltrim($ref, '/');
                if (is_dir($candidate)) {
                    $roots[] = $candidate;
                }
            }
        }

        if ($missionId !== null && $this->dataRoot !== '') {
            $eng = $this->dataRoot . '/workspaces';
            if (is_dir($eng)) {
                // Include any session workspace dirs for this mission if present.
                $matches = glob($eng . '/*') ?: [];
                foreach ($matches as $path) {
                    if (!is_dir($path)) {
                        continue;
                    }
                    $meta = $path . '/workspace.json';
                    if (!is_file($meta)) {
                        $roots[] = $path;
                        continue;
                    }
                    $data = json_decode((string) file_get_contents($meta), true);
                    if (is_array($data) && ($data['missionId'] ?? null) === $missionId) {
                        $roots[] = $path;
                        if (isset($data['mounts']['work']) && is_string($data['mounts']['work']) && is_dir($data['mounts']['work'])) {
                            $roots[] = $data['mounts']['work'];
                        }
                    }
                }
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * @param list<string> $roots
     * @param list<array<string, mixed>> $artifacts
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function deriveSignals(array $roots, array $artifacts, array $events): array
    {
        $signals = [
            'tests_executed' => false,
            'build_completed' => false,
        ];

        foreach ($artifacts as $art) {
            $name = strtolower((string) ($art['name'] ?? ''));
            $kind = strtolower((string) ($art['kind'] ?? ''));
            if (str_contains($name, 'test') || str_contains($kind, 'test')) {
                $signals['tests_executed'] = true;
            }
            if (str_contains($name, 'build') || str_contains($kind, 'build')) {
                $signals['build_completed'] = true;
            }
        }

        foreach ($events as $event) {
            $type = strtolower((string) ($event['type'] ?? ''));
            $msg = strtolower((string) ($event['data']['message'] ?? $event['message'] ?? ''));
            if (str_contains($type, 'test') || str_contains($msg, 'test')) {
                $signals['tests_executed'] = true;
            }
            if (str_contains($type, 'build') || str_contains($msg, 'build')) {
                $signals['build_completed'] = true;
            }
        }

        foreach ($roots as $root) {
            if (is_file($root . '/.aep/signals.json')) {
                $data = json_decode((string) file_get_contents($root . '/.aep/signals.json'), true);
                if (is_array($data)) {
                    foreach (['tests_executed', 'build_completed'] as $key) {
                        if (array_key_exists($key, $data)) {
                            $signals[$key] = $data[$key];
                        }
                    }
                }
            }
            // Convention: presence of test/build marker files under reference workspace.
            if (is_file($root . '/.aep/tests-passed') || is_file($root . '/reports/tests.xml')) {
                $signals['tests_executed'] = true;
            }
            if (is_file($root . '/.aep/build-ok')
                || is_file($root . '/reports/build.log')
                || is_file($root . '/reports/build.txt')) {
                $signals['build_completed'] = true;
            }
        }

        return $signals;
    }
}
