<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Artifact;

use Aep\Application\Artifact\ArtifactKind;
use Aep\Application\Artifact\ArtifactManifest;
use Aep\Application\Artifact\Workspace;
use Aep\Application\Artifact\WorkspaceManager;

/**
 * Filesystem-backed workspace manager.
 *
 * Layout:
 *   {root}/missions/{missionId}/runs/{runId}/
 *     workspace.json
 *     manifest.json
 *     artifacts/{logs,reports,validation,checkpoints,files,archives}/
 */
final class FilesystemWorkspaceManager implements WorkspaceManager
{
    private readonly string $root;

    public function __construct(
        string $root,
        private readonly ManifestSerializer $serializer = new ManifestSerializer(),
    ) {
        $root = rtrim($root, "/\\");
        if ($root === '') {
            throw new \InvalidArgumentException('FilesystemWorkspaceManager root must be non-empty.');
        }
        $this->root = $root;
    }

    public function ensure(
        string $missionId,
        string $runId,
        string $occurredAtUtc,
        ?string $projectId = null,
    ): Workspace {
        $missionId = Workspace::assertSafeId($missionId, 'missionId');
        $runId = Workspace::assertSafeId($runId, 'runId');
        $workspaceId = Workspace::idFor($missionId, $runId);
        $path = $this->workspacePath($missionId, $runId);

        if (is_file($path . DIRECTORY_SEPARATOR . 'workspace.json')) {
            return $this->get($workspaceId);
        }

        $this->createLayout($path);
        $workspace = new Workspace(
            $workspaceId,
            $missionId,
            $runId,
            $path,
            Workspace::STATUS_ACTIVE,
            $occurredAtUtc,
            $occurredAtUtc,
            $projectId,
        );
        $this->writeWorkspace($workspace);

        $manifest = new ArtifactManifest(
            $workspaceId,
            $missionId,
            $runId,
            Workspace::STATUS_ACTIVE,
            $occurredAtUtc,
            $occurredAtUtc,
            [],
            $projectId,
        );
        $this->serializer->writeAtomic($path . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);

        return $workspace;
    }

    public function get(string $workspaceId): Workspace
    {
        $path = $this->resolveRootByWorkspaceId($workspaceId);
        $file = $path . DIRECTORY_SEPARATOR . 'workspace.json';
        if (!is_file($file)) {
            throw new \RuntimeException('Workspace not found: ' . $workspaceId);
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read workspace.json for ' . $workspaceId);
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid workspace.json for ' . $workspaceId, 0, $e);
        }
        if (!is_array($data)) {
            throw new \RuntimeException('workspace.json must decode to an object.');
        }
        $data['rootPath'] = $path;

        return Workspace::fromArray($data);
    }

    public function seal(string $workspaceId, string $occurredAtUtc): Workspace
    {
        $workspace = $this->get($workspaceId)->withStatus(Workspace::STATUS_SEALED, $occurredAtUtc);
        $this->writeWorkspace($workspace);

        return $workspace;
    }

    public function markArchived(string $workspaceId, string $occurredAtUtc): Workspace
    {
        $workspace = $this->get($workspaceId)->withStatus(Workspace::STATUS_ARCHIVED, $occurredAtUtc);
        $this->writeWorkspace($workspace);

        return $workspace;
    }

    public function markPurged(string $workspaceId, string $occurredAtUtc): Workspace
    {
        $workspace = $this->get($workspaceId)->withStatus(Workspace::STATUS_PURGED, $occurredAtUtc);
        $this->writeWorkspace($workspace);

        return $workspace;
    }

    public function exists(string $workspaceId): bool
    {
        try {
            $this->get($workspaceId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function listWorkspaces(): array
    {
        $missionsDir = $this->root . DIRECTORY_SEPARATOR . 'missions';
        if (!is_dir($missionsDir)) {
            return [];
        }

        $out = [];
        $missionEntries = scandir($missionsDir);
        if ($missionEntries === false) {
            return [];
        }
        foreach ($missionEntries as $missionId) {
            if ($missionId === '.' || $missionId === '..') {
                continue;
            }
            $runsDir = $missionsDir . DIRECTORY_SEPARATOR . $missionId . DIRECTORY_SEPARATOR . 'runs';
            if (!is_dir($runsDir)) {
                continue;
            }
            $runEntries = scandir($runsDir);
            if ($runEntries === false) {
                continue;
            }
            foreach ($runEntries as $runId) {
                if ($runId === '.' || $runId === '..') {
                    continue;
                }
                $workspaceFile = $runsDir . DIRECTORY_SEPARATOR . $runId . DIRECTORY_SEPARATOR . 'workspace.json';
                if (!is_file($workspaceFile)) {
                    continue;
                }
                try {
                    $out[] = $this->get(Workspace::idFor($missionId, $runId));
                } catch (\Throwable) {
                    // skip corrupt
                }
            }
        }

        return $out;
    }

    public function deleteWorkspaceTree(string $workspaceId): void
    {
        $path = $this->resolveRootByWorkspaceId($workspaceId);
        $this->removePath($path);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function manifestPathFor(Workspace $workspace): string
    {
        return $workspace->rootPath() . DIRECTORY_SEPARATOR . 'manifest.json';
    }

    private function createLayout(string $path): void
    {
        $dirs = [$path, $path . DIRECTORY_SEPARATOR . 'artifacts'];
        foreach (ArtifactKind::all() as $kind) {
            $dirs[] = $path . DIRECTORY_SEPARATOR . 'artifacts' . DIRECTORY_SEPARATOR . ArtifactKind::directoryName($kind);
        }
        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create workspace directory: ' . $dir);
            }
        }
    }

    private function writeWorkspace(Workspace $workspace): void
    {
        $file = $workspace->rootPath() . DIRECTORY_SEPARATOR . 'workspace.json';
        $payload = json_encode($workspace->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temp, $payload . "\n") === false) {
            throw new \RuntimeException('Unable to write workspace.json temp file.');
        }
        if (!rename($temp, $file)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to replace workspace.json.');
        }
    }

    private function workspacePath(string $missionId, string $runId): string
    {
        return $this->root
            . DIRECTORY_SEPARATOR . 'missions'
            . DIRECTORY_SEPARATOR . $missionId
            . DIRECTORY_SEPARATOR . 'runs'
            . DIRECTORY_SEPARATOR . $runId;
    }

    private function resolveRootByWorkspaceId(string $workspaceId): string
    {
        [$missionId, $runId] = Workspace::parseId($workspaceId);

        return $this->workspacePath($missionId, $runId);
    }

    private function removePath(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removePath($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
    }
}
