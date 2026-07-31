<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionControl;

use Aep\Application\Artifact\ArtifactService;
use Aep\Application\CodeReview\Policy\PatchPolicyFactory;
use Aep\Application\CodeReview\Service\ChangeManifestBuilder;
use Aep\Application\CodeReview\Service\ConflictDetector;
use Aep\Application\CodeReview\Service\DiffValidator;
use Aep\Application\CodeReview\Service\MergeReadinessEvaluator;
use Aep\Application\CodeReview\Service\PatchPipelineService;
use Aep\Application\CodeReview\Service\PatchQueryService;
use Aep\Application\CodeReview\Service\PatchScorer;
use Aep\Application\CodeReview\Service\SelfReviewOrchestrator;
use Aep\Application\CodeReview\Service\StaticAnalysisRunner;
use Aep\Application\CodeReview\Service\TestExecutionRunner;
use Aep\Application\EngineeringExecution\Service\ArtifactCapture;
use Aep\Application\EngineeringExecution\Service\ContextPackager;
use Aep\Application\EngineeringExecution\Service\DiffCollector;
use Aep\Application\EngineeringExecution\Service\EngineeringExecutionOrchestrator;
use Aep\Application\EngineeringExecution\Service\EngineeringExecutionQueryService;
use Aep\Application\EngineeringExecution\Service\PromptPipeline;
use Aep\Application\EngineeringExecution\Service\ResultNormalizer;
use Aep\Application\EngineeringExecution\Service\WorkspacePreparer;
use Aep\Application\EngineeringWorkspace\Service\EngineeringWorkspaceService;
use Aep\Application\EngineeringWorkspace\Service\WorkspaceQueryService;
use Aep\Application\Execution\ExecutionService;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionControl\Auth\AuthService;
use Aep\Application\MissionControl\Command\MissionControlCommandFacade;
use Aep\Application\MissionControl\Health\HealthService;
use Aep\Application\MissionControl\Query\ApprovalQueryService;
use Aep\Application\MissionControl\Query\ArtifactQueryService;
use Aep\Application\MissionControl\Query\DashboardQueryService;
use Aep\Application\MissionControl\Query\MissionQueryService;
use Aep\Application\MissionControl\Query\ProjectQueryService;
use Aep\Application\MissionControl\Query\ValidationQueryService;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\MissionEngine\DefaultMissionPlanFactory;
use Aep\Application\MissionEngine\MissionEngine;
use Aep\Application\MissionExecution\Service\AutonomousMissionService;
use Aep\Application\MissionExecution\Service\ClarificationEngine;
use Aep\Application\MissionExecution\Service\ContextAssembler;
use Aep\Application\MissionExecution\Service\EngineeringMemoryService;
use Aep\Application\MissionExecution\Service\LaunchFacade;
use Aep\Application\MissionExecution\Service\MissionPlanner;
use Aep\Application\MissionExecution\Service\MissionValidator;
use Aep\Application\MissionExecution\Service\ParameterExtractor;
use Aep\Application\MissionExecution\Service\WorkflowSelector;
use Aep\Application\Project\ProjectCommandService;
use Aep\Application\Validation\ValidationPipeline;
use Aep\Infrastructure\Artifact\FilesystemArtifactStore;
use Aep\Infrastructure\Artifact\FilesystemWorkspaceManager;
use Aep\Infrastructure\CodeReview\Adapter\PatchCreationAdapter;
use Aep\Infrastructure\CodeReview\Provider\ConfigReviewProviderRegistry;
use Aep\Infrastructure\CodeReview\Provider\HeuristicLocalReviewProvider;
use Aep\Infrastructure\CodeReview\Provider\StubReviewProvider;
use Aep\Infrastructure\CodeReview\Store\FilesystemPatchStore;
use Aep\Infrastructure\CodeReview\Store\JsonPatchSettingsStore;
use Aep\Infrastructure\EngineeringExecution\Bridge\LegacyExecutorBridgeProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\LocalAgentProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\StubCliProvider;
use Aep\Infrastructure\EngineeringExecution\Registry\ConfigProviderRegistry;
use Aep\Infrastructure\EngineeringExecution\Store\JsonExecutionSessionStore;
use Aep\Infrastructure\EngineeringExecution\Store\JsonExecutionSettingsStore;
use Aep\Infrastructure\EngineeringWorkspace\Git\GitWorktreeCheckout;
use Aep\Infrastructure\EngineeringWorkspace\Store\FilesystemEngineeringWorkspaceStore;
use Aep\Infrastructure\EngineeringWorkspace\Store\JsonWorkspaceSettingsStore;
use Aep\Infrastructure\Execution\DeclarativeLocalExecutor;
use Aep\Infrastructure\Execution\ProviderRoutingExecutor;
use Aep\Infrastructure\MissionControl\Auth\FileSessionStore;
use Aep\Infrastructure\MissionControl\Auth\JsonUserStore;
use Aep\Infrastructure\MissionControl\Catalog\JsonMissionCatalog;
use Aep\Infrastructure\MissionControl\Catalog\JsonProjectCatalog;
use Aep\Infrastructure\MissionControl\Catalog\JsonRunCatalog;
use Aep\Infrastructure\MissionEngine\JsonFileMissionRunRepository;
use Aep\Infrastructure\MissionExecution\Store\JsonConversationRepository;
use Aep\Infrastructure\MissionExecution\Store\JsonIntakeRepository;
use Aep\Infrastructure\MissionExecution\Store\JsonPlanRepository;
use Aep\Infrastructure\MissionExecution\Store\JsonProjectMemoryRepository;
use Aep\Infrastructure\MissionExecution\Understanding\HeuristicPromptUnderstanding;
use Aep\Infrastructure\Persistence\JsonFileMissionRepository;
use Aep\Infrastructure\Persistence\JsonFileProjectRepository;
use Aep\Infrastructure\Validation\DeclarativeContextValidationStep;

/**
 * Composition root for Mission Control API (adapter wiring only).
 */
final class MissionControlKernel
{
    private AuthService $auth;
    private MissionQueryService $missions;
    private ProjectQueryService $projects;
    private DashboardQueryService $dashboard;
    private ApprovalQueryService $approvals;
    private ArtifactQueryService $artifacts;
    private ValidationQueryService $validation;
    private MissionControlCommandFacade $commands;
    private HealthService $health;
    private AutonomousMissionService $ame;
    private EngineeringExecutionQueryService $executionQuery;
    private EngineeringExecutionOrchestrator $executionOrchestrator;
    private EngineeringWorkspaceService $engineeringWorkspaces;
    private WorkspaceQueryService $workspaceQuery;
    private PatchPipelineService $patchPipeline;
    private PatchQueryService $patchQuery;
    private string $dataRoot;

    public function __construct(string $dataRoot, string $version = '0.5.0')
    {
        $this->dataRoot = rtrim($dataRoot, "/\\");
        if ($this->dataRoot === '') {
            throw new \InvalidArgumentException('dataRoot is required.');
        }

        $missionsDir = $this->dataRoot . '/missions';
        $projectsDir = $this->dataRoot . '/projects';
        $runsDir = $this->dataRoot . '/runs';
        $artifactsDir = $this->dataRoot . '/artifacts';
        $authDir = $this->dataRoot . '/auth';
        $sessionsDir = $authDir . '/sessions';
        $ameDir = $this->dataRoot . '/ame';
        $executionDir = $this->dataRoot . '/execution';
        $workspacesDir = $this->dataRoot . '/workspaces';
        $gitCacheDir = $this->dataRoot . '/git-cache';
        $patchesDir = $this->dataRoot . '/patches';

        foreach ([
            $missionsDir,
            $projectsDir,
            $runsDir,
            $artifactsDir,
            $authDir,
            $sessionsDir,
            $ameDir,
            $executionDir,
            $workspacesDir,
            $gitCacheDir,
            $patchesDir,
        ] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create data directory: ' . $dir);
            }
        }

        $missionRepo = new JsonFileMissionRepository($missionsDir);
        $projectRepo = new JsonFileProjectRepository($projectsDir);
        $runRepo = new JsonFileMissionRunRepository($runsDir);

        $missionCatalog = new JsonMissionCatalog($missionRepo, $missionsDir);
        $projectCatalog = new JsonProjectCatalog($projectRepo, $projectsDir);
        $runCatalog = new JsonRunCatalog($runRepo, $runsDir);

        $workspaces = new FilesystemWorkspaceManager($artifactsDir);
        $artifactStore = new FilesystemArtifactStore($workspaces);
        $artifactService = new ArtifactService($workspaces, $artifactStore);

        $missionCommands = new MissionCommandService($missionRepo);
        $projectCommands = new ProjectCommandService($projectRepo);

        $legacyLocal = new DeclarativeLocalExecutor();
        $sessionStore = new JsonExecutionSessionStore($executionDir);
        $settingsStore = new JsonExecutionSettingsStore($executionDir);
        $providerConfig = $this->loadProviderConfig();
        $registry = new ConfigProviderRegistry($providerConfig, [
            'local-agent' => static fn (array $options): LocalAgentProvider => new LocalAgentProvider($options),
            'stub-cli' => static fn (array $options): StubCliProvider => new StubCliProvider($options),
            'legacy-local' => static fn (array $options): LegacyExecutorBridgeProvider => new LegacyExecutorBridgeProvider($legacyLocal, $options),
        ]);

        $workspaceStore = new FilesystemEngineeringWorkspaceStore($workspacesDir);
        $workspaceSettings = new JsonWorkspaceSettingsStore($workspacesDir);
        $this->engineeringWorkspaces = new EngineeringWorkspaceService(
            $workspaceStore,
            $workspaceSettings,
            new GitWorktreeCheckout($gitCacheDir),
            $artifactService,
        );
        $this->workspaceQuery = new WorkspaceQueryService($this->engineeringWorkspaces, $workspaceSettings);

        $this->executionOrchestrator = new EngineeringExecutionOrchestrator(
            $registry,
            $sessionStore,
            new PromptPipeline(),
            new ContextPackager(),
            new WorkspacePreparer($this->engineeringWorkspaces, $executionDir),
            new DiffCollector(),
            new ArtifactCapture($artifactService),
            new ResultNormalizer(),
        );
        $this->executionQuery = new EngineeringExecutionQueryService($registry, $sessionStore, $settingsStore);

        $patchSettings = new JsonPatchSettingsStore($patchesDir);
        $reviewConfig = $this->loadReviewProviderConfig();
        $reviewRegistry = new ConfigReviewProviderRegistry($reviewConfig, [
            'heuristic-local' => static fn (array $o): HeuristicLocalReviewProvider => new HeuristicLocalReviewProvider($o),
            'stub-review' => static fn (array $o): StubReviewProvider => new StubReviewProvider($o),
        ]);
        $policySet = PatchPolicyFactory::fromSettings($patchSettings->get());
        $this->patchPipeline = new PatchPipelineService(
            new FilesystemPatchStore($patchesDir),
            $patchSettings,
            new ChangeManifestBuilder(),
            new DiffValidator(),
            new StaticAnalysisRunner(),
            new TestExecutionRunner(dirname(__DIR__, 3) . '/tests/run.php'),
            new PatchScorer(),
            new SelfReviewOrchestrator($reviewRegistry),
            new MergeReadinessEvaluator($policySet),
            new ConflictDetector(),
        );
        $this->patchQuery = new PatchQueryService($this->patchPipeline, $patchSettings, $reviewRegistry);

        $routing = new ProviderRoutingExecutor($legacyLocal, $this->executionOrchestrator, $settingsStore);
        $execution = new ExecutionService(
            new PatchCreationAdapter(
                $routing,
                $this->patchPipeline,
                $patchSettings,
                $sessionStore,
                $this->engineeringWorkspaces,
            )
        );
        $validationPipeline = new ValidationPipeline([new DeclarativeContextValidationStep()]);
        $engine = new MissionEngine(
            $missionCommands,
            $missionRepo,
            $runRepo,
            new DefaultMissionPlanFactory(),
            $execution,
            $validationPipeline
        );

        $this->auth = new AuthService(
            new JsonUserStore($authDir . '/users.json'),
            new FileSessionStore($sessionsDir)
        );

        $this->missions = new MissionQueryService($missionCatalog, $runCatalog);
        $this->approvals = new ApprovalQueryService($missionCatalog, $this->missions);
        $this->projects = new ProjectQueryService($projectCatalog, $runCatalog, $this->missions);
        $this->artifacts = new ArtifactQueryService($artifactService, $workspaces);
        $this->validation = new ValidationQueryService(
            $missionCatalog,
            $this->missions,
            $workspaces,
            $artifactService
        );
        $this->health = new HealthService(
            $this->dataRoot,
            $missionsDir,
            $projectsDir,
            $runsDir,
            $artifactsDir,
            $version
        );
        $this->dashboard = new DashboardQueryService(
            $missionCatalog,
            $runCatalog,
            $this->missions,
            $this->approvals,
            $this->health
        );
        $this->commands = new MissionControlCommandFacade($missionCommands, $projectCommands, $engine);

        $memoryRepo = new JsonProjectMemoryRepository($ameDir . '/memory');
        $intakeRepo = new JsonIntakeRepository($ameDir . '/intakes');
        $conversationRepo = new JsonConversationRepository($ameDir . '/conversations');
        $planRepo = new JsonPlanRepository($ameDir . '/plans');
        $this->ame = new AutonomousMissionService(
            $intakeRepo,
            $conversationRepo,
            $planRepo,
            $memoryRepo,
            new HeuristicPromptUnderstanding(),
            new ClarificationEngine(),
            new MissionValidator(),
            new MissionPlanner(
                new WorkflowSelector(),
                new ParameterExtractor(),
                new ContextAssembler($missionCatalog, $memoryRepo)
            ),
            new LaunchFacade($missionCommands, $engine),
            new EngineeringMemoryService($memoryRepo),
            $this->projects
        );

        $this->bootstrapAdmin();
    }

    public function auth(): AuthService
    {
        return $this->auth;
    }

    public function missions(): MissionQueryService
    {
        return $this->missions;
    }

    public function projects(): ProjectQueryService
    {
        return $this->projects;
    }

    public function dashboard(): DashboardQueryService
    {
        return $this->dashboard;
    }

    public function approvals(): ApprovalQueryService
    {
        return $this->approvals;
    }

    public function artifacts(): ArtifactQueryService
    {
        return $this->artifacts;
    }

    public function validation(): ValidationQueryService
    {
        return $this->validation;
    }

    public function commands(): MissionControlCommandFacade
    {
        return $this->commands;
    }

    public function health(): HealthService
    {
        return $this->health;
    }

    public function ame(): AutonomousMissionService
    {
        return $this->ame;
    }

    public function execution(): EngineeringExecutionQueryService
    {
        return $this->executionQuery;
    }

    public function executionOrchestrator(): EngineeringExecutionOrchestrator
    {
        return $this->executionOrchestrator;
    }

    public function workspaces(): WorkspaceQueryService
    {
        return $this->workspaceQuery;
    }

    public function engineeringWorkspaces(): EngineeringWorkspaceService
    {
        return $this->engineeringWorkspaces;
    }

    public function patches(): PatchQueryService
    {
        return $this->patchQuery;
    }

    public function patchPipeline(): PatchPipelineService
    {
        return $this->patchPipeline;
    }

    public function dataRoot(): string
    {
        return $this->dataRoot;
    }

    /** @return array<string, mixed> */
    private function loadReviewProviderConfig(): array
    {
        $configured = getenv('AEP_REVIEW_PROVIDERS_CONFIG');
        $path = is_string($configured) && $configured !== ''
            ? $configured
            : dirname(__DIR__, 3) . '/deploy/review-providers.json';
        if (!is_file($path)) {
            return [
                'providers' => [
                    [
                        'id' => 'heuristic-local',
                        'type' => 'heuristic-local',
                        'enabled' => true,
                        'displayName' => 'Heuristic Local Review',
                    ],
                ],
            ];
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid review providers config.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function loadProviderConfig(): array
    {
        $configured = getenv('AEP_EXECUTION_PROVIDERS_CONFIG');
        $path = is_string($configured) && $configured !== ''
            ? $configured
            : dirname(__DIR__, 3) . '/deploy/execution-providers.json';
        if (!is_file($path)) {
            return [
                'providers' => [
                    [
                        'id' => 'local-agent',
                        'type' => 'local-agent',
                        'enabled' => true,
                        'displayName' => 'Local Agent',
                    ],
                ],
            ];
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid execution providers config.');
        }

        return $data;
    }

    private function bootstrapAdmin(): void
    {
        $username = getenv('AEP_BOOTSTRAP_ADMIN_USERNAME') ?: 'admin';
        $password = getenv('AEP_BOOTSTRAP_ADMIN_PASSWORD') ?: 'changeme';
        $display = getenv('AEP_BOOTSTRAP_ADMIN_DISPLAY') ?: 'AEP Administrator';
        $this->auth->ensureBootstrapAdmin($username, $password, $display, Utc::now());
    }
}
