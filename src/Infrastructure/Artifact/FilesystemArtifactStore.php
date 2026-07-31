<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Artifact;

use Aep\Application\Artifact\ArchiveCapableArtifactStore;
use Aep\Application\Artifact\Artifact;
use Aep\Application\Artifact\ArtifactKind;
use Aep\Application\Artifact\ArtifactManifest;
use Aep\Application\Artifact\ArtifactMetadata;
use Aep\Application\Artifact\Workspace;

/**
 * Filesystem artifact store. Manifest.json is the sole discovery source.
 */
final class FilesystemArtifactStore implements ArchiveCapableArtifactStore
{
    public function __construct(
        private readonly FilesystemWorkspaceManager $workspaces,
        private readonly ManifestSerializer $serializer = new ManifestSerializer(),
    ) {
    }

    public function put(
        Workspace $workspace,
        string $artifactId,
        ArtifactKind $kind,
        string $name,
        string $contents,
        string $contentType,
        bool $persistent,
        ArtifactMetadata $metadata,
        string $occurredAtUtc,
    ): Artifact {
        if (!$workspace->isActive()) {
            throw new \RuntimeException('Cannot put artifact into sealed workspace.');
        }

        $artifactId = Artifact::assertLogicalId($artifactId);
        $name = $this->assertSafeName($name);
        $relativePath = 'artifacts/'
            . ArtifactKind::directoryName($kind->toString())
            . '/'
            . $this->fileNameFor($artifactId, $name);

        $absolute = $workspace->rootPath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create artifact directory: ' . $dir);
        }

        if (file_put_contents($absolute, $contents) === false) {
            throw new \RuntimeException('Unable to write artifact: ' . $absolute);
        }

        $hash = 'sha256:' . hash('sha256', $contents);
        $artifact = new Artifact(
            $artifactId,
            $workspace->workspaceId(),
            $kind,
            $name,
            $relativePath,
            $contentType,
            strlen($contents),
            $hash,
            $occurredAtUtc,
            $persistent,
            $metadata,
        );

        $manifest = $this->manifest($workspace->workspaceId())->withArtifact($artifact, $occurredAtUtc);
        $this->saveManifest($manifest);

        return $artifact;
    }

    public function get(string $workspaceId, string $artifactId): Artifact
    {
        $artifact = $this->manifest($workspaceId)->find($artifactId);
        if ($artifact === null) {
            throw new \RuntimeException('Artifact not found: ' . $artifactId);
        }

        return $artifact;
    }

    public function readContents(string $workspaceId, string $artifactId): string
    {
        $artifact = $this->get($workspaceId, $artifactId);
        $path = $this->absolutePath($workspaceId, $artifact->relativePath());
        if (!is_file($path)) {
            throw new \RuntimeException('Artifact blob missing for ' . $artifactId);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read artifact blob for ' . $artifactId);
        }

        return $raw;
    }

    public function list(string $workspaceId, ?string $kind = null): array
    {
        $manifest = $this->manifest($workspaceId);
        if ($kind === null) {
            return $manifest->artifacts();
        }

        return $manifest->byKind($kind);
    }

    public function manifest(string $workspaceId): ArtifactManifest
    {
        $workspace = $this->workspaces->get($workspaceId);

        return $this->serializer->readFile($this->workspaces->manifestPathFor($workspace));
    }

    public function saveManifest(ArtifactManifest $manifest): void
    {
        $workspace = $this->workspaces->get($manifest->workspaceId());
        $this->serializer->writeAtomic($this->workspaces->manifestPathFor($workspace), $manifest);
    }

    public function remove(string $workspaceId, string $artifactId, string $occurredAtUtc): void
    {
        $workspace = $this->workspaces->get($workspaceId);
        if (!$workspace->isActive()) {
            throw new \RuntimeException('Cannot delete artifact from sealed workspace.');
        }

        $manifest = $this->manifest($workspaceId);
        $artifact = $manifest->find($artifactId);
        if ($artifact === null) {
            return;
        }

        $path = $this->absolutePath($workspaceId, $artifact->relativePath());
        if (is_file($path)) {
            @unlink($path);
        }

        $this->saveManifest($manifest->withoutArtifact($artifactId, $occurredAtUtc));
    }

    public function absolutePath(string $workspaceId, string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
            throw new \InvalidArgumentException('Invalid relativePath.');
        }
        $workspace = $this->workspaces->get($workspaceId);

        return $workspace->rootPath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    public function archive(Workspace $workspace, string $occurredAtUtc): Artifact
    {
        if (!class_exists(\PharData::class)) {
            throw new \RuntimeException('PharData is required for workspace archive.');
        }

        $archiveId = 'archive.workspace';
        $name = 'workspace-' . $workspace->runId() . '.tar';
        $relativePath = 'artifacts/archives/' . $name;
        $tarPath = $workspace->rootPath() . DIRECTORY_SEPARATOR . 'artifacts' . DIRECTORY_SEPARATOR . 'archives' . DIRECTORY_SEPARATOR . $name;
        $gzPath = $tarPath . '.gz';

        if (is_file($tarPath)) {
            @unlink($tarPath);
        }
        if (is_file($gzPath)) {
            @unlink($gzPath);
        }

        $staging = $workspace->rootPath() . DIRECTORY_SEPARATOR . 'artifacts' . DIRECTORY_SEPARATOR . 'archives' . DIRECTORY_SEPARATOR . '.staging-' . bin2hex(random_bytes(4));
        if (!mkdir($staging, 0775, true) && !is_dir($staging)) {
            throw new \RuntimeException('Unable to create archive staging directory.');
        }

        try {
            // Stage only persistent artifact files listed in the manifest (no filesystem scan for discovery).
            $manifest = $this->manifest($workspace->workspaceId());
            foreach ($manifest->artifacts() as $artifact) {
                if ($artifact->kind()->is(ArtifactKind::ARCHIVE)) {
                    continue;
                }
                $source = $this->absolutePath($workspace->workspaceId(), $artifact->relativePath());
                if (!is_file($source)) {
                    continue;
                }
                $dest = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $artifact->relativePath());
                $destDir = dirname($dest);
                if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                    throw new \RuntimeException('Unable to stage artifact path.');
                }
                if (!copy($source, $dest)) {
                    throw new \RuntimeException('Unable to stage artifact for archive: ' . $artifact->artifactId());
                }
            }

            // Also include workspace.json + manifest.json
            copy(
                $workspace->rootPath() . DIRECTORY_SEPARATOR . 'workspace.json',
                $staging . DIRECTORY_SEPARATOR . 'workspace.json'
            );
            copy(
                $workspace->rootPath() . DIRECTORY_SEPARATOR . 'manifest.json',
                $staging . DIRECTORY_SEPARATOR . 'manifest.json'
            );

            $phar = new \PharData($tarPath);
            $phar->buildFromDirectory($staging);
            $phar->compress(\Phar::GZ);
            unset($phar);
            if (is_file($tarPath)) {
                @unlink($tarPath);
            }
            if (!is_file($gzPath)) {
                throw new \RuntimeException('Compressed archive was not created.');
            }

            $contents = file_get_contents($gzPath);
            if ($contents === false) {
                throw new \RuntimeException('Unable to read compressed archive.');
            }

            // Register archive in manifest without using put() (workspace may already be sealed).
            $artifact = new Artifact(
                $archiveId,
                $workspace->workspaceId(),
                new ArtifactKind(ArtifactKind::ARCHIVE),
                $name . '.gz',
                $relativePath . '.gz',
                'application/gzip',
                strlen($contents),
                'sha256:' . hash('sha256', $contents),
                $occurredAtUtc,
                true,
                new ArtifactMetadata($workspace->missionId(), $workspace->runId(), $workspace->projectId(), null, null, null, null, 'artifact.archive'),
            );

            // Move final file to expected relative path name with .gz
            $finalRelative = $relativePath . '.gz';
            $finalAbsolute = $workspace->rootPath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $finalRelative);
            if ($gzPath !== $finalAbsolute && is_file($gzPath)) {
                // already correct path from PharData compress
            }

            $updated = $this->manifest($workspace->workspaceId())->withArtifact($artifact, $occurredAtUtc);
            $this->saveManifest($updated);

            return $artifact;
        } finally {
            $this->removePath($staging);
        }
    }

    private function fileNameFor(string $artifactId, string $name): string
    {
        // Prefer deterministic id-based filename; keep extension from name when present.
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if (is_string($ext) && $ext !== '') {
            return $artifactId . '.' . $ext;
        }

        return $artifactId . '.bin';
    }

    private function assertSafeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new \InvalidArgumentException('Artifact name must be a plain filename without path separators.');
        }

        return $name;
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
