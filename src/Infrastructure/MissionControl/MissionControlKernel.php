<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionControl;

use Aep\Application\Artifact\ArtifactService;
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
use Aep\Application\Project\ProjectCommandService;
use Aep\Application\Validation\ValidationPipeline;
use Aep\Infrastructure\Artifact\FilesystemArtifactStore;
use Aep\Infrastructure\Artifact\FilesystemWorkspaceManager;
use Aep\Infrastructure\Execution\DeclarativeLocalExecutor;
use Aep\Infrastructure\MissionControl\Auth\FileSessionStore;
use Aep\Infrastructure\MissionControl\Auth\JsonUserStore;
use Aep\Infrastructure\MissionControl\Catalog\JsonMissionCatalog;
use Aep\Infrastructure\MissionControl\Catalog\JsonProjectCatalog;
use Aep\Infrastructure\MissionControl\Catalog\JsonRunCatalog;
use Aep\Infrastructure\MissionEngine\JsonFileMissionRunRepository;
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
    private string $dataRoot;

    public function __construct(string $dataRoot, string $version = '0.1.0')
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

        foreach ([$missionsDir, $projectsDir, $runsDir, $artifactsDir, $authDir, $sessionsDir] as $dir) {
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
        $execution = new ExecutionService(new DeclarativeLocalExecutor());
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

    public function dataRoot(): string
    {
        return $this->dataRoot;
    }

    private function bootstrapAdmin(): void
    {
        $username = getenv('AEP_BOOTSTRAP_ADMIN_USERNAME') ?: 'admin';
        $password = getenv('AEP_BOOTSTRAP_ADMIN_PASSWORD') ?: 'changeme';
        $display = getenv('AEP_BOOTSTRAP_ADMIN_DISPLAY') ?: 'AEP Administrator';
        $this->auth->ensureBootstrapAdmin($username, $password, $display, Utc::now());
    }
}
