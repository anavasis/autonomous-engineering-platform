<?php

declare(strict_types=1);

namespace Tests\Support;

use Aep\Application\Artifact\ArtifactService;
use Aep\Application\EngineeringExecution\Service\WorkspacePreparer;
use Aep\Application\EngineeringWorkspace\Service\EngineeringWorkspaceService;
use Aep\Infrastructure\Artifact\FilesystemArtifactStore;
use Aep\Infrastructure\Artifact\FilesystemWorkspaceManager;
use Aep\Infrastructure\EngineeringWorkspace\Store\FilesystemEngineeringWorkspaceStore;
use Aep\Infrastructure\EngineeringWorkspace\Store\JsonWorkspaceSettingsStore;

final class EngineeringWorkspaceTestFactory
{
    /**
     * @return array{service: EngineeringWorkspaceService, preparer: WorkspacePreparer, artifacts: ArtifactService}
     */
    public static function make(string $root): array
    {
        $workspacesDir = $root . '/workspaces';
        $artifactsDir = $root . '/artifacts';
        foreach ([$workspacesDir, $artifactsDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
        $artifactMgr = new FilesystemWorkspaceManager($artifactsDir);
        $artifacts = new ArtifactService($artifactMgr, new FilesystemArtifactStore($artifactMgr));
        $service = new EngineeringWorkspaceService(
            new FilesystemEngineeringWorkspaceStore($workspacesDir),
            new JsonWorkspaceSettingsStore($workspacesDir),
            null,
            $artifacts,
        );

        return [
            'service' => $service,
            'preparer' => new WorkspacePreparer($service),
            'artifacts' => $artifacts,
        ];
    }
}
