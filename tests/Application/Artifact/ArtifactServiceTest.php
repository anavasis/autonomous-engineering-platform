<?php

declare(strict_types=1);

namespace Tests\Application\Artifact;

use Aep\Application\Artifact\ArtifactKind;
use Aep\Application\Artifact\ArtifactMetadata;
use Aep\Application\Artifact\ArtifactService;
use Aep\Application\Artifact\Workspace;
use Aep\Infrastructure\Artifact\FilesystemArtifactStore;
use Aep\Infrastructure\Artifact\FilesystemWorkspaceManager;
use Tests\Support\Assert;

/**
 * ORCH-R12 Application ArtifactService tests.
 */
final class ArtifactServiceTest
{
    public function test_workspace_creation(): void
    {
        $root = $this->tempRoot();
        try {
            $service = $this->service($root);
            $ws = $service->ensureWorkspace('msn_art_1', 'run_art_1', '2026-07-31T01:00:00Z', 'proj_1');
            Assert::same(Workspace::idFor('msn_art_1', 'run_art_1'), $ws->workspaceId());
            Assert::same(Workspace::STATUS_ACTIVE, $ws->status());
            Assert::true(is_dir($ws->rootPath()));
            Assert::true(is_file($ws->rootPath() . '/workspace.json'));
            Assert::true(is_file($ws->rootPath() . '/manifest.json'));
            Assert::true(is_dir($ws->rootPath() . '/artifacts/reports'));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_put_get_list_and_manifest_updates(): void
    {
        $root = $this->tempRoot();
        try {
            $service = $this->service($root);
            $ws = $service->ensureWorkspace('msn_art_2', 'run_art_2', '2026-07-31T01:00:00Z');

            $artifact = $service->put(
                'msn_art_2',
                'run_art_2',
                'report.validation',
                ArtifactKind::REPORT,
                'validation-report.json',
                '{"ok":true}',
                '2026-07-31T01:00:01Z',
                true,
                'application/json',
                new ArtifactMetadata('msn_art_2', 'run_art_2', null, 'aep.default_mission', '1.0.0', 'abc', 'run_validation', 'validation.run'),
            );

            Assert::same('report.validation', $artifact->artifactId());
            Assert::same(ArtifactKind::REPORT, $artifact->kind()->toString());
            Assert::true(str_starts_with($artifact->contentHash(), 'sha256:'));

            $loaded = $service->get($ws->workspaceId(), 'report.validation');
            Assert::same('report.validation', $loaded->artifactId());
            Assert::same('{"ok":true}', $service->readContents($ws->workspaceId(), 'report.validation'));

            $list = $service->list($ws->workspaceId(), ArtifactKind::REPORT);
            Assert::same(1, count($list));

            $manifest = $service->manifest($ws->workspaceId());
            Assert::same(1, count($manifest->artifacts()));
            Assert::same('report.validation', $manifest->artifacts()[0]->artifactId());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_deterministic_ids(): void
    {
        $root = $this->tempRoot();
        try {
            $service = $this->service($root);
            foreach (['report.validation', 'checkpoint.engine', 'log.timeline'] as $id) {
                $service->put(
                    'msn_art_3',
                    'run_art_3',
                    $id,
                    str_starts_with($id, 'report') ? ArtifactKind::REPORT : (str_starts_with($id, 'checkpoint') ? ArtifactKind::CHECKPOINT : ArtifactKind::LOG),
                    $id . '.json',
                    '{}',
                    '2026-07-31T01:00:00Z',
                );
            }
            $ids = array_map(
                static fn ($a) => $a->artifactId(),
                $service->list(Workspace::idFor('msn_art_3', 'run_art_3'))
            );
            sort($ids);
            Assert::same(['checkpoint.engine', 'log.timeline', 'report.validation'], $ids);

            Assert::throws(\InvalidArgumentException::class, static function () use ($service): void {
                $service->put('msn_art_3', 'run_art_3', 'BadId', ArtifactKind::FILE, 'x.bin', 'x', '2026-07-31T01:00:00Z');
            });
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_seal_immutability(): void
    {
        $root = $this->tempRoot();
        try {
            $service = $this->service($root);
            $ws = $service->ensureWorkspace('msn_art_4', 'run_art_4', '2026-07-31T01:00:00Z');
            $service->put(
                'msn_art_4',
                'run_art_4',
                'file.note',
                ArtifactKind::FILE,
                'note.txt',
                'hello',
                '2026-07-31T01:00:00Z',
                false,
            );
            $service->put(
                'msn_art_4',
                'run_art_4',
                'report.summary',
                ArtifactKind::REPORT,
                'summary.json',
                '{}',
                '2026-07-31T01:00:00Z',
                true,
            );

            $sealed = $service->seal($ws->workspaceId(), '2026-07-31T01:05:00Z');
            Assert::same(Workspace::STATUS_SEALED, $sealed->status());
            // non-persistent removed
            Assert::same(null, $service->manifest($ws->workspaceId())->find('file.note'));
            Assert::true($service->manifest($ws->workspaceId())->find('report.summary') !== null);

            Assert::throws(\RuntimeException::class, static function () use ($service): void {
                $service->put(
                    'msn_art_4',
                    'run_art_4',
                    'file.after',
                    ArtifactKind::FILE,
                    'after.txt',
                    'nope',
                    '2026-07-31T01:06:00Z',
                );
            });

            // reads still work
            Assert::same('{}', $service->readContents($ws->workspaceId(), 'report.summary'));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_metadata(): void
    {
        $root = $this->tempRoot();
        try {
            $service = $this->service($root);
            $ws = $service->ensureWorkspace('msn_art_5', 'run_art_5', '2026-07-31T01:00:00Z', 'proj_meta');
            $service->setWorkflowMetadata($ws->workspaceId(), 'aep.default_mission', '1.0.0', str_repeat('a', 64));
            $service->put(
                'msn_art_5',
                'run_art_5',
                'validation.outcomes',
                ArtifactKind::VALIDATION,
                'outcomes.json',
                '[]',
                '2026-07-31T01:00:01Z',
                true,
                'application/json',
                new ArtifactMetadata(
                    'msn_art_5',
                    'run_art_5',
                    'proj_meta',
                    'aep.default_mission',
                    '1.0.0',
                    str_repeat('a', 64),
                    'run_validation',
                    'validation.run',
                    ['env' => 'test'],
                ),
            );

            $manifest = $service->manifest($ws->workspaceId());
            Assert::same('aep.default_mission', $manifest->workflowId());
            Assert::same('1.0.0', $manifest->workflowVersion());
            $meta = $manifest->find('validation.outcomes')?->metadata();
            Assert::true($meta !== null);
            Assert::same('validation.run', $meta->producedBy());
            Assert::same('run_validation', $meta->stepId());
            Assert::same('test', $meta->labels()['env'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    private function service(string $root): ArtifactService
    {
        $workspaces = new FilesystemWorkspaceManager($root);
        $store = new FilesystemArtifactStore($workspaces);

        return new ArtifactService($workspaces, $store);
    }

    private function tempRoot(): string
    {
        $dir = sys_get_temp_dir() . '/aep_art_app_' . bin2hex(random_bytes(6));
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
