<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionControl;

use Aep\Application\Agent\Policy\AgentPolicyFactory;
use Aep\Application\Agent\Service\AgentCoordinator;
use Aep\Application\Agent\Service\AgentQueryService;
use Aep\Application\Agent\Service\AgentRouter;
use Aep\Application\Optimization\Model\CostModel;
use Aep\Application\Optimization\Service\BudgetManager;
use Aep\Application\Optimization\Service\CapacityManager;
use Aep\Application\Optimization\Service\CapacityPlanner;
use Aep\Application\Optimization\Service\OptimizationEngine;
use Aep\Application\Optimization\Service\OptimizationQueryService;
use Aep\Application\Optimization\Service\ResourceAllocator as OptimizationResourceAllocator;
use Aep\Application\Optimization\Service\ResourceManager;
use Aep\Application\Governance\Policy\ApprovalPolicy;
use Aep\Application\Governance\Policy\CompliancePolicy;
use Aep\Application\Governance\Policy\SecurityPolicy;
use Aep\Application\Governance\Service\AuditManager;
use Aep\Application\Governance\Service\GovernanceManager;
use Aep\Application\Governance\Service\GovernanceObserveFacade;
use Aep\Application\Governance\Service\GovernanceQueryService;
use Aep\Application\Governance\Service\ReleaseManager;
use Aep\Application\Governance\Service\ReleasePipeline;
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
use Aep\Application\Knowledge\Policy\KnowledgeRetrievalPolicyFactory;
use Aep\Application\Knowledge\Service\ArchivePolicy;
use Aep\Application\Knowledge\Service\ForgetPolicy;
use Aep\Application\Knowledge\Service\KnowledgeCaptureService;
use Aep\Application\Knowledge\Service\KnowledgeGraph;
use Aep\Application\Knowledge\Service\KnowledgeQueryService;
use Aep\Application\Knowledge\Service\KnowledgeRanker;
use Aep\Application\Knowledge\Service\KnowledgeRetrievalService;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\Planning\Policy\SchedulingPolicyFactory;
use Aep\Application\Planning\Service\CriticalPathAnalyzer;
use Aep\Application\Planning\Service\DependencyManager;
use Aep\Application\Planning\Service\EstimateService;
use Aep\Application\Planning\Service\FailureRecoveryPolicy;
use Aep\Application\Planning\Service\PlanningPipelineService;
use Aep\Application\Planning\Service\PlanningQueryService;
use Aep\Application\Planning\Service\ProgramPlanner;
use Aep\Application\Planning\Service\ProviderAllocator;
use Aep\Application\Planning\Service\Replanner;
use Aep\Application\Planning\Service\ResourceAllocator;
use Aep\Application\Planning\Service\Scheduler;
use Aep\Application\Planning\Service\WorkspaceAllocator;
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
use Aep\Infrastructure\Agent\Adapter\AgentAssignmentAdapter;
use Aep\Infrastructure\Agent\Registry\ConfigAgentRegistry;
use Aep\Infrastructure\Agent\Store\FilesystemAgentStore;
use Aep\Infrastructure\Agent\Store\JsonAgentSettingsStore;
use Aep\Infrastructure\Optimization\Adapter\OptimizationPlanningAdapter;
use Aep\Infrastructure\Optimization\Adapter\OptimizationProviderAdapter;
use Aep\Infrastructure\Optimization\Store\FilesystemOptimizationStore;
use Aep\Infrastructure\Optimization\Store\JsonOptimizationSettingsStore;
use Aep\Infrastructure\Governance\Adapter\GovernanceLaunchAdapter;
use Aep\Infrastructure\Governance\Adapter\GovernanceObserveAdapter;
use Aep\Infrastructure\Governance\Store\FilesystemGovernanceStore;
use Aep\Infrastructure\Governance\Store\JsonGovernanceSettingsStore;
use Aep\Infrastructure\Artifact\FilesystemArtifactStore;
use Aep\Infrastructure\Artifact\FilesystemWorkspaceManager;
use Aep\Infrastructure\CodeReview\Adapter\PatchCreationAdapter;
use Aep\Infrastructure\CodeReview\Provider\ConfigReviewProviderRegistry;
use Aep\Infrastructure\CodeReview\Provider\HeuristicLocalReviewProvider;
use Aep\Infrastructure\CodeReview\Provider\StubReviewProvider;
use Aep\Infrastructure\CodeReview\Store\FilesystemPatchStore;
use Aep\Infrastructure\CodeReview\Store\JsonPatchSettingsStore;
use Aep\Infrastructure\EngineeringExecution\Bridge\LegacyExecutorBridgeProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\ClaudeCodeCliProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\CodexCliProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\CursorCliProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\ExternalCliProvider;
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
use Aep\Infrastructure\Knowledge\Adapter\KnowledgeCaptureAdapter;
use Aep\Infrastructure\Knowledge\Adapter\KnowledgeRetrievalAdapter;
use Aep\Infrastructure\Knowledge\Embedding\LocalLexicalEmbeddingProvider;
use Aep\Infrastructure\Knowledge\Embedding\StubEmbeddingProvider;
use Aep\Infrastructure\Knowledge\Provider\ConfigEmbeddingProviderRegistry;
use Aep\Infrastructure\Knowledge\Store\FilesystemKnowledgeStore;
use Aep\Infrastructure\Knowledge\Store\JsonKnowledgeSettingsStore;
use Aep\Infrastructure\Planning\Adapter\PlanningKnowledgeAdapter;
use Aep\Infrastructure\Planning\Adapter\PlanningLaunchAdapter;
use Aep\Infrastructure\Planning\Store\FilesystemProgramStore;
use Aep\Infrastructure\Planning\Store\JsonPlanningSettingsStore;
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
    private KnowledgeQueryService $knowledgeQuery;
    private KnowledgeCaptureService $knowledgeCapture;
    private PlanningQueryService $planningQuery;
    private AgentQueryService $agentQuery;
    private OptimizationQueryService $optimizationQuery;
    private GovernanceQueryService $governanceQuery;
    private GovernanceObserveAdapter $governanceObserve;
    private string $dataRoot;

    public function __construct(string $dataRoot, string $version = '1.4.0')
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
        $knowledgeDir = $this->dataRoot . '/knowledge';
        $planningDir = $this->dataRoot . '/planning';
        $agentsDir = $this->dataRoot . '/agents';
        $optimizationDir = $this->dataRoot . '/optimization';
        $governanceDir = $this->dataRoot . '/governance';

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
            $knowledgeDir,
            $planningDir,
            $agentsDir,
            $optimizationDir,
            $governanceDir,
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
            'cursor-cli' => static fn (array $options): CursorCliProvider => new CursorCliProvider($options),
            'claude-code-cli' => static fn (array $options): ClaudeCodeCliProvider => new ClaudeCodeCliProvider($options),
            'codex-cli' => static fn (array $options): CodexCliProvider => new CodexCliProvider($options),
            'external-cli' => static fn (array $options): ExternalCliProvider => new ExternalCliProvider($options),
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

        $knowledgeSettings = new JsonKnowledgeSettingsStore($knowledgeDir);
        $knowledgeStore = new FilesystemKnowledgeStore($knowledgeDir);
        $embeddingRegistry = new ConfigEmbeddingProviderRegistry($this->loadEmbeddingProviderConfig(), [
            'local_lexical' => static fn (array $o): LocalLexicalEmbeddingProvider => new LocalLexicalEmbeddingProvider(
                is_int($o['dimensions'] ?? null) ? $o['dimensions'] : 64
            ),
            'stub_embed' => static fn (array $o): StubEmbeddingProvider => new StubEmbeddingProvider(),
        ]);
        $ks = $knowledgeSettings->get();
        $knowledgeGraph = new KnowledgeGraph($knowledgeStore);
        $archivePolicy = new ArchivePolicy(
            is_int($ks['archiveMaxAgeDays'] ?? null) ? (int) $ks['archiveMaxAgeDays'] : 180,
            is_numeric($ks['archiveMinUsefulness'] ?? null) ? (float) $ks['archiveMinUsefulness'] : 0.15,
        );
        $forgetPolicy = new ForgetPolicy(
            is_int($ks['forgetArchiveTtlDays'] ?? null) ? (int) $ks['forgetArchiveTtlDays'] : 90,
        );
        $this->knowledgeCapture = new KnowledgeCaptureService(
            $knowledgeStore,
            $knowledgeSettings,
            $knowledgeGraph,
            $embeddingRegistry,
            $archivePolicy,
            $forgetPolicy,
        );
        $retrievalPolicies = KnowledgeRetrievalPolicyFactory::fromSettings($ks);
        $knowledgeRetrieval = new KnowledgeRetrievalService(
            $knowledgeStore,
            $knowledgeSettings,
            new KnowledgeRanker($retrievalPolicies),
            $embeddingRegistry,
            $knowledgeGraph,
        );
        $this->knowledgeQuery = new KnowledgeQueryService(
            $knowledgeStore,
            $knowledgeSettings,
            $this->knowledgeCapture,
            $knowledgeRetrieval,
            $knowledgeGraph,
            $embeddingRegistry,
        );

        $optimizationSettings = new JsonOptimizationSettingsStore($optimizationDir);
        $optimizationStore = new FilesystemOptimizationStore($optimizationDir);
        $optimizationConfig = $this->loadOptimizationConfig();
        $resourceManager = new ResourceManager($optimizationStore);
        $resourceManager->seedFromConfig($optimizationConfig);
        $capacityManager = new CapacityManager($optimizationStore, $optimizationSettings);
        $capacityManager->seedFromConfig($optimizationConfig);
        foreach (is_array($optimizationConfig['costModels'] ?? null) ? $optimizationConfig['costModels'] : [] as $cm) {
            if (is_array($cm) && is_string($cm['providerId'] ?? null)) {
                $optimizationStore->saveCostModel(CostModel::fromArray($cm));
            }
        }
        $budgetManager = new BudgetManager($optimizationStore, $optimizationSettings);
        $budgetManager->ensureDefaults();
        $capacityPlanner = new CapacityPlanner($optimizationStore);
        $optAllocator = new OptimizationResourceAllocator($capacityManager, $budgetManager);
        $optimizationEngine = new OptimizationEngine(
            $optimizationStore,
            $optimizationSettings,
            $budgetManager,
            $capacityManager,
            $capacityPlanner,
            $optAllocator,
        );
        $this->optimizationQuery = new OptimizationQueryService(
            $optimizationStore,
            $optimizationSettings,
            $optimizationEngine,
            $resourceManager,
            $capacityManager,
            $budgetManager,
        );

        $governanceSettings = new JsonGovernanceSettingsStore($governanceDir);
        $governanceStore = new FilesystemGovernanceStore($governanceDir);
        $governanceConfig = $this->loadGovernanceConfig();
        $auditManager = new AuditManager($governanceStore);
        $governanceManager = new GovernanceManager($governanceStore, $governanceSettings, $auditManager);
        $governanceManager->seedFromConfig($governanceConfig);
        $releasePipeline = new ReleasePipeline(
            $governanceStore,
            $governanceSettings,
            new CompliancePolicy(),
            new SecurityPolicy(),
        );
        $releaseManager = new ReleaseManager(
            $governanceStore,
            $governanceSettings,
            $releasePipeline,
            $auditManager,
            new ApprovalPolicy(),
        );
        $governanceObserveFacade = new GovernanceObserveFacade($releaseManager, $governanceManager, $governanceSettings);
        $this->governanceObserve = new GovernanceObserveAdapter($governanceObserveFacade);
        $this->governanceQuery = new GovernanceQueryService(
            $governanceStore,
            $governanceSettings,
            $governanceManager,
            $releaseManager,
            $releasePipeline,
            $auditManager,
            $governanceObserveFacade,
        );

        $routing = new ProviderRoutingExecutor($legacyLocal, $this->executionOrchestrator, $settingsStore);
        $optimizedRouting = new OptimizationProviderAdapter($routing, $optimizationEngine, $optimizationSettings);
        $execution = new ExecutionService(
            new KnowledgeCaptureAdapter(
                new PatchCreationAdapter(
                    new KnowledgeRetrievalAdapter(
                        $optimizedRouting,
                        $knowledgeRetrieval,
                        $knowledgeSettings,
                    ),
                    $this->patchPipeline,
                    $patchSettings,
                    $sessionStore,
                    $this->engineeringWorkspaces,
                ),
                $this->knowledgeCapture,
                $knowledgeSettings,
                $sessionStore,
                $this->patchPipeline,
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

        $planningSettings = new JsonPlanningSettingsStore($planningDir);
        $programStore = new FilesystemProgramStore($planningDir);
        $deps = new DependencyManager();
        $estimates = new EstimateService();
        $criticalPath = new CriticalPathAnalyzer();
        $programPlanner = new ProgramPlanner($deps, $estimates, $criticalPath, $programStore);
        $replanner = new Replanner($programStore, $programPlanner, $deps, $estimates, $criticalPath);

        $agentSettings = new JsonAgentSettingsStore($agentsDir);
        $agentStore = new FilesystemAgentStore($agentsDir);
        $agentRegistry = new ConfigAgentRegistry($agentStore, $this->loadAgentConfig());
        $agentPolicies = AgentPolicyFactory::fromSettings($agentSettings->get());
        $agentRouter = new AgentRouter($agentRegistry, $agentPolicies);
        $agentCoordinator = new AgentCoordinator($agentStore, $agentSettings, $agentRouter);
        $this->agentQuery = new AgentQueryService($agentStore, $agentSettings, $agentCoordinator, $agentRouter);

        $schedulingPolicies = SchedulingPolicyFactory::fromSettings($planningSettings->get());
        $scheduler = new Scheduler(
            $programStore,
            $planningSettings,
            $schedulingPolicies,
            $deps,
            new ResourceAllocator(),
            new ProviderAllocator(),
            new WorkspaceAllocator(),
            new FailureRecoveryPolicy(),
            new GovernanceLaunchAdapter(
                new OptimizationPlanningAdapter(
                    new AgentAssignmentAdapter(
                        new PlanningLaunchAdapter($missionCommands, $engine),
                        $agentCoordinator,
                        $agentSettings,
                    ),
                    $optimizationEngine,
                    $optimizationSettings,
                ),
                $governanceObserveFacade,
                $governanceSettings,
            ),
            $criticalPath,
            $replanner,
        );
        $planningPipeline = new PlanningPipelineService(
            $programStore,
            $planningSettings,
            $programPlanner,
            $scheduler,
            $replanner,
            $deps,
            $criticalPath,
        );
        $this->planningQuery = new PlanningQueryService(
            $planningPipeline,
            $planningSettings,
            $deps,
            $criticalPath,
            new ResourceAllocator(),
            new WorkspaceAllocator(),
        );
        // Keep knowledge adapter available for future plan-time enrichment without coupling planner ctor.
        new PlanningKnowledgeAdapter($this->knowledgeQuery);

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

    public function knowledge(): KnowledgeQueryService
    {
        return $this->knowledgeQuery;
    }

    public function knowledgeCapture(): KnowledgeCaptureService
    {
        return $this->knowledgeCapture;
    }

    public function planning(): PlanningQueryService
    {
        return $this->planningQuery;
    }

    public function agents(): AgentQueryService
    {
        return $this->agentQuery;
    }

    public function optimization(): OptimizationQueryService
    {
        return $this->optimizationQuery;
    }

    public function governance(): GovernanceQueryService
    {
        return $this->governanceQuery;
    }

    public function governanceObserve(): GovernanceObserveAdapter
    {
        return $this->governanceObserve;
    }

    public function dataRoot(): string
    {
        return $this->dataRoot;
    }

    /** @return array<string, mixed> */
    private function loadAgentConfig(): array
    {
        $configured = getenv('AEP_AGENTS_CONFIG');
        $path = is_string($configured) && $configured !== ''
            ? $configured
            : dirname(__DIR__, 3) . '/deploy/agents.json';
        if (!is_file($path)) {
            return ['agents' => []];
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid agents config.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function loadOptimizationConfig(): array
    {
        $path = getenv('AEP_OPTIMIZATION_CONFIG') ?: (dirname(__DIR__, 3) . '/deploy/optimization.json');
        if (!is_file($path)) {
            return ['resources' => [], 'providers' => [], 'costModels' => [], 'agents' => [], 'workspace' => []];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid optimization config.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function loadGovernanceConfig(): array
    {
        $path = getenv('AEP_GOVERNANCE_CONFIG') ?: (dirname(__DIR__, 3) . '/deploy/governance.json');
        if (!is_file($path)) {
            return ['environments' => [], 'targets' => []];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid governance config.');
        }

        return $data;
    }

    private function loadEmbeddingProviderConfig(): array
    {
        $configured = getenv('AEP_EMBEDDING_PROVIDERS_CONFIG');
        $path = is_string($configured) && $configured !== ''
            ? $configured
            : dirname(__DIR__, 3) . '/deploy/embedding-providers.json';
        if (!is_file($path)) {
            return [
                'default' => 'local_lexical',
                'providers' => [
                    [
                        'id' => 'local_lexical',
                        'type' => 'local_lexical',
                        'enabled' => true,
                        'displayName' => 'Local Lexical Embedding',
                    ],
                ],
            ];
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid embedding providers config.');
        }

        return $data;
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
