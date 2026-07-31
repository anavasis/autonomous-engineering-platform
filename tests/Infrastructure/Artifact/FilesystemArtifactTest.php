<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Artifact;

use Aep\Application\Artifact\ArtifactKind;
use Aep\Application\Artifact\ArtifactMetadata;
use Aep\Application\Artifact\ArtifactService;
use Aep\Application\Artifact\Workspace;
use Aep\Infrastructure\Artifact\CleanupService;
use Aep\Infrastructure\Artifact\FilesystemArtifactStore;
use Aep\Infrastructure\Artifact\FilesystemWorkspaceManager;
use Aep\Infrastructure\Artifact\ManifestSerializer;
use Aep\Infrastructure\Artifact\RetentionPolicy;
use Tests\Support\Assert;

/**
 * ORCH-R12 Infrastructure filesystem artifact tests.
 */
final class FilesystemArtifactTest
{
    public function test_filesystem_layout(): void
    {
        $root = $this->tempRoot();
        try {
            $workspaces = new FilesystemWorkspaceManager($root);
            $ws = $workspaces->ensure('msn_fs_1', 'run_fs_1', '2026-07-31T02:00:00Z');
            Assert::true(is_dir($root . '/missions/msn_fs_1/runs/run_fs_1/artifacts/logs'));
            Assert::true(is_dir($root . '/missions/msn_fs_1/runs/run_fs_1/artifacts/archives'));
            Assert::same($root . '/missions/msn_fs_1/runs/run_fs_1', $ws->rootPath());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_manifest_persistence(): void
    {
        $root = $this->tempRoot();
        try {
            $workspaces = new FilesystemWorkspaceManager($root);
            $store = new FilesystemArtifactStore($workspaces);
            $service = new ArtifactService($workspaces, $store);
            $ws = $service->ensureWorkspace('msn_fs_2', 'run_fs_2', '2026-07-31T02:00:00Z');
            $service->put(
                'msn_fs_2',
                'run_fs_2',
                'checkpoint.engine',
                ArtifactKind::CHECKPOINT,
                'checkpoint.json',
                '{"cursor":1}',
                '2026-07-31T02:00:01Z',
            );

            $serializer = new ManifestSerializer();
            $loaded = $serializer->readFile($ws->rootPath() . '/manifest.json');
            Assert::same(1, count($loaded->artifacts()));
            Assert::same('checkpoint.engine', $loaded->artifacts()[0]->artifactId());
            Assert::same(1, $loaded->toArray()['schemaVersion'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_cleanup_non_persistent(): void
    {
        $root = $this->tempRoot();
        try {
            $workspaces = new FilesystemWorkspaceManager($root);
            $store = new FilesystemArtifactStore($workspaces);
            $service = new ArtifactService($workspaces, $store);
            $cleanup = new CleanupService($workspaces, $store);
            $ws = $service->ensureWorkspace('msn_fs_3', 'run_fs_3', '2026-07-31T02:00:00Z');
            $service->put('msn_fs_3', 'run_fs_3', 'file.tmpnote', ArtifactKind::FILE, 'tmp.txt', 'x', '2026-07-31T02:00:00Z', false);
            $service->put('msn_fs_3', 'run_fs_3', 'log.timeline', ArtifactKind::LOG, 'timeline.json', '[]', '2026-07-31T02:00:00Z', true);

            $removed = $cleanup->cleanupNonPersistent($ws->workspaceId(), '2026-07-31T02:01:00Z');
            Assert::contains('file.tmpnote', $removed);
            Assert::same(null, $service->manifest($ws->workspaceId())->find('file.tmpnote'));
            Assert::true($service->manifest($ws->workspaceId())->find('log.timeline') !== null);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_retention(): void
    {
        $root = $this->tempRoot();
        try {
            $workspaces = new FilesystemWorkspaceManager($root);
            $store = new FilesystemArtifactStore($workspaces);
            $service = new ArtifactService($workspaces, $store);
            $policy = new RetentionPolicy(0, 0, 0, 1); // purge anything sealed/archived older than 0 days, keep 1 latest
            $cleanup = new CleanupService($workspaces, $store, $policy);

            $old = $service->ensureWorkspace('msn_fs_4', 'run_old', '2026-06-01T00:00:00Z');
            $service->put('msn_fs_4', 'run_old', 'report.old', ArtifactKind::REPORT, 'old.json', '{}', '2026-06-01T00:00:00Z');
            $service->seal($old->workspaceId(), '2026-06-01T00:00:01Z');

            $newer = $service->ensureWorkspace('msn_fs_4', 'run_new', '2026-07-31T00:00:00Z');
            $service->put('msn_fs_4', 'run_new', 'report.new', ArtifactKind::REPORT, 'new.json', '{}', '2026-07-31T00:00:00Z');
            $service->seal($newer->workspaceId(), '2026-07-31T00:00:01Z');

            $purged = $cleanup->applyRetention('2026-07-31T12:00:00Z');
            Assert::contains($old->workspaceId(), $purged);
            Assert::true(!$workspaces->exists($old->workspaceId()));
            Assert::true($workspaces->exists($newer->workspaceId()));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_archive_with_phardata(): void
    {
        if (!class_exists(\PharData::class)) {
            Assert::true(false, 'PharData unavailable');
        }
        $root = $this->tempRoot();
        try {
            $workspaces = new FilesystemWorkspaceManager($root);
            $store = new FilesystemArtifactStore($workspaces);
            $service = new ArtifactService($workspaces, $store);
            $ws = $service->ensureWorkspace('msn_fs_5', 'run_fs_5', '2026-07-31T02:00:00Z');
            $service->put(
                'msn_fs_5',
                'run_fs_5',
                'report.validation',
                ArtifactKind::REPORT,
                'validation-report.json',
                '{"passed":true}',
                '2026-07-31T02:00:01Z',
                true,
                'application/json',
                new ArtifactMetadata('msn_fs_5', 'run_fs_5'),
            );
            $service->seal($ws->workspaceId(), '2026-07-31T02:00:02Z');

            $archive = $service->archive($ws->workspaceId(), '2026-07-31T02:00:03Z');
            Assert::same('archive.workspace', $archive->artifactId());
            Assert::same(ArtifactKind::ARCHIVE, $archive->kind()->toString());
            Assert::same(Workspace::STATUS_ARCHIVED, $service->getWorkspace($ws->workspaceId())->status());

            $gz = $service->readContents($ws->workspaceId(), 'archive.workspace');
            Assert::true(strlen($gz) > 20);
            Assert::true(is_file($ws->rootPath() . '/artifacts/archives/workspace-run_fs_5.tar.gz'));
        } finally {
            $this->removeDir($root);
        }
    }

    private function tempRoot(): string
    {
        $dir = sys_get_temp_dir() . '/aep_art_fs_' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        if (is_file($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeDir($dir . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($dir);
    }
}

function ArtifactManifestSchemaOk(mixed $version): bool
{
    return $version === 1;
}
