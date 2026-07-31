<?php

declare(strict_types=1);

namespace Tests\Application\EngineeringWorkspace;

use Aep\Application\EngineeringExecution\Model\PromptBundle;
use Aep\Application\EngineeringWorkspace\Model\EngineeringWorkspace;
use Tests\Support\Assert;
use Tests\Support\EngineeringWorkspaceTestFactory;

final class EngineeringWorkspaceServiceTest
{
    public function test_ensure_for_session_provisions_mounts_and_fingerprint(): void
    {
        $root = sys_get_temp_dir() . '/aep_ws_' . bin2hex(random_bytes(4));
        try {
            $factory = EngineeringWorkspaceTestFactory::make($root);
            $service = $factory['service'];
            $prompt = new PromptBundle('sys', 'user objective');
            $ws = $service->ensureForSession(
                'esess_1',
                'msn_1',
                'run_1',
                $prompt,
                ['src/a.txt' => 'hello'],
                ['src/'],
            );

            Assert::same(EngineeringWorkspace::STATUS_IN_USE, $ws->status());
            Assert::true($ws->rootPath() !== '');
            Assert::true(is_file($ws->rootPath() . '/mounts/prompt/PROMPT.md'));
            Assert::true(is_file($ws->rootPath() . '/context/src/a.txt'));
            Assert::true(str_starts_with($ws->reproFingerprint(), 'sha256:'));
            Assert::same(1, $ws->toArray()['schemaVersion'] ?? null);
            Assert::true(isset($ws->toArray()['compatibility']['executionContract']));

            $again = $service->ensureForSession(
                'esess_2',
                'msn_1',
                'run_1',
                $prompt,
                ['src/a.txt' => 'hello'],
            );
            Assert::same($ws->workspaceId(), $again->workspaceId());
            Assert::true(in_array('esess_2', $again->sessionIds(), true));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_snapshot_seal_cleanup_lifecycle(): void
    {
        $root = sys_get_temp_dir() . '/aep_ws_' . bin2hex(random_bytes(4));
        try {
            $service = EngineeringWorkspaceTestFactory::make($root)['service'];
            $ws = $service->ensureForSession(
                'esess_x',
                'msn_x',
                'run_x',
                new PromptBundle('s', 'u'),
                ['src/b.txt' => 'x'],
            );
            $snap = $service->snapshot($ws->workspaceId());
            Assert::true(str_starts_with($snap, 'snap_'));
            $sealed = $service->seal($ws->workspaceId());
            Assert::same(EngineeringWorkspace::STATUS_SEALED, $sealed->status());
            $service->cleanup($ws->workspaceId());
            $after = $service->get($ws->workspaceId());
            Assert::same(EngineeringWorkspace::STATUS_PURGED, $after?->status());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_health_check_and_timeline(): void
    {
        $root = sys_get_temp_dir() . '/aep_ws_' . bin2hex(random_bytes(4));
        try {
            $service = EngineeringWorkspaceTestFactory::make($root)['service'];
            $ws = $service->ensureForSession(
                'esess_h',
                'msn_h',
                'run_h',
                new PromptBundle('s', 'u'),
                [],
            );
            $health = $service->healthCheck($ws);
            Assert::true($health->isAvailable());
            $timeline = $service->timeline($ws->workspaceId());
            Assert::true(count($timeline) >= 1);
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
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
