<?php

declare(strict_types=1);

namespace Tests\Application\MissionControl;

use Aep\Application\Mission\Command\CreateMission;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionControl\Query\ApprovalQueryService;
use Aep\Application\MissionControl\Query\DashboardQueryService;
use Aep\Application\MissionControl\Query\MissionQueryService;
use Aep\Application\MissionControl\Query\ProjectQueryService;
use Aep\Application\MissionControl\Health\HealthService;
use Aep\Application\Project\Command\CreateProject;
use Aep\Application\Project\ProjectCommandService;
use Aep\Domain\Mission\ValueObject\MissionState;
use Aep\Infrastructure\MissionControl\Catalog\JsonMissionCatalog;
use Aep\Infrastructure\MissionControl\Catalog\JsonProjectCatalog;
use Aep\Infrastructure\MissionControl\Catalog\JsonRunCatalog;
use Aep\Infrastructure\MissionEngine\JsonFileMissionRunRepository;
use Aep\Infrastructure\Persistence\JsonFileMissionRepository;
use Aep\Infrastructure\Persistence\JsonFileProjectRepository;
use Tests\Support\Assert;

final class MissionControlQueryTest
{
    public function test_dashboard_and_lists_reflect_persisted_state(): void
    {
        $root = sys_get_temp_dir() . '/aep_mc_query_' . bin2hex(random_bytes(4));
        $missionsDir = $root . '/missions';
        $projectsDir = $root . '/projects';
        $runsDir = $root . '/runs';
        mkdir($missionsDir, 0777, true);
        mkdir($projectsDir, 0777, true);
        mkdir($runsDir, 0777, true);

        try {
            $missionRepo = new JsonFileMissionRepository($missionsDir);
            $projectRepo = new JsonFileProjectRepository($projectsDir);
            $runRepo = new JsonFileMissionRunRepository($runsDir);

            $missionCatalog = new JsonMissionCatalog($missionRepo, $missionsDir);
            $projectCatalog = new JsonProjectCatalog($projectRepo, $projectsDir);
            $runCatalog = new JsonRunCatalog($runRepo, $runsDir);

            $missions = new MissionCommandService($missionRepo);
            $projects = new ProjectCommandService($projectRepo);

            $projects->create(new CreateProject(
                'proj_demo',
                'demo-platform',
                'Demo Platform',
                'user',
                'tester',
                '2026-07-31T02:10:00Z',
                'Demo'
            ));
            $missions->create(new CreateMission(
                'msn_demo',
                'github',
                'anavasis/demo',
                'Inspect demo repository',
                'user',
                'tester',
                '2026-07-31T02:11:00Z'
            ));

            $missionQuery = new MissionQueryService($missionCatalog, $runCatalog);
            $projectQuery = new ProjectQueryService($projectCatalog, $runCatalog, $missionQuery);
            $approvals = new ApprovalQueryService($missionCatalog, $missionQuery);
            $health = new HealthService($root, $missionsDir, $projectsDir, $runsDir, $root . '/artifacts');
            $dashboard = new DashboardQueryService($missionCatalog, $runCatalog, $missionQuery, $approvals, $health);

            $missionList = $missionQuery->list();
            Assert::same(1, count($missionList));
            Assert::same('msn_demo', $missionList[0]['id']);
            Assert::same(MissionState::DRAFT, $missionList[0]['state']);

            $projectList = $projectQuery->list();
            Assert::same(1, count($projectList));
            Assert::same('proj_demo', $projectList[0]['id']);
            Assert::same('unbound', $projectList[0]['repositoryStatus']);

            $snap = $dashboard->snapshot();
            Assert::same(1, $snap['activeMissions']);
            Assert::same(0, $snap['completedMissions']);
            Assert::same('ok', $snap['systemHealth']['status']);
        } finally {
            $this->removeDir($root);
        }
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
