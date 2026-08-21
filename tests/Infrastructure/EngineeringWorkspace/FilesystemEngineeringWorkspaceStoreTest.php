<?php

declare(strict_types=1);

namespace Tests\Infrastructure\EngineeringWorkspace;

use Aep\Application\EngineeringWorkspace\Model\EngineeringWorkspace;
use Aep\Application\EngineeringWorkspace\Model\WorkspaceHealth;
use Aep\Infrastructure\EngineeringWorkspace\Store\FilesystemEngineeringWorkspaceStore;
use Tests\Support\Assert;

final class FilesystemEngineeringWorkspaceStoreTest
{
    public function test_save_find_index_and_lock(): void
    {
        $root = sys_get_temp_dir() . '/aep_wstore_' . bin2hex(random_bytes(4));
        try {
            $store = new FilesystemEngineeringWorkspaceStore($root);
            $ws = new EngineeringWorkspace(
                'ewsp_abc123',
                'msn_1',
                'run_1',
                EngineeringWorkspace::STATUS_PLANNED,
                '2026-07-31T12:00:00Z',
                '2026-07-31T12:00:00Z',
                '',
                null,
                ['esess_1'],
            );
            $path = $store->ensureTree($ws->workspaceId());
            $ws->setRootPath($path);
            $ws->setHealth(new WorkspaceHealth('ok', 'fine', '2026-07-31T12:00:00Z'));
            $store->save($ws);

            Assert::true($store->find('ewsp_abc123') !== null);
            Assert::same('ewsp_abc123', $store->findByMissionRun('msn_1', 'run_1')?->workspaceId());
            Assert::same('ewsp_abc123', $store->findBySession('esess_1')?->workspaceId());
            Assert::true($store->acquireLock('ewsp_abc123', 'esess_1'));
            Assert::true($store->acquireLock('ewsp_abc123', 'other') === false);
            $store->releaseLock('ewsp_abc123', 'esess_1');
            Assert::true($store->acquireLock('ewsp_abc123', 'other'));

            $store->writeFile('ewsp_abc123', 'context/src/a.txt', 'hi');
            $size = $store->measureSize('ewsp_abc123');
            Assert::true($size['files'] >= 1);
            Assert::true($size['bytes'] >= 2);
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
