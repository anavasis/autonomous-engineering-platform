<?php

declare(strict_types=1);

namespace Tests\Infrastructure\CodeReview;

use Aep\Application\CodeReview\Model\ChangeManifest;
use Aep\Application\CodeReview\Model\Patch;
use Aep\Infrastructure\CodeReview\Store\FilesystemPatchStore;
use Tests\Support\Assert;

final class FilesystemPatchStoreTest
{
    public function test_save_find_and_timeline(): void
    {
        $root = sys_get_temp_dir() . '/aep_pstore_' . bin2hex(random_bytes(4));
        try {
            $store = new FilesystemPatchStore($root);
            $patch = new Patch(
                'patch_abc123',
                'msn_1',
                'run_1',
                Patch::STATUS_DRAFT,
                '2026-07-31T12:00:00Z',
                '2026-07-31T12:00:00Z',
                "--- a/src/a.php\n+++ b/src/a.php\n",
                manifest: new ChangeManifest(
                    [['path' => 'src/a.php', 'changeType' => 'modify', 'additions' => 1, 'deletions' => 0, 'owner' => 'platform']],
                    true,
                    [],
                    null,
                    null,
                    null,
                    'sha256:abc',
                    1,
                    0,
                ),
            );
            $patch->setReproFingerprint('sha256:fp');
            $patch->setIntegrityHash('sha256:ih');
            $store->save($patch);
            $store->appendTimeline('patch_abc123', ['at' => '2026-07-31T12:00:00Z', 'type' => 'test', 'data' => []]);

            $loaded = $store->find('patch_abc123');
            Assert::true($loaded !== null);
            Assert::same('msn_1', $loaded?->missionId());
            Assert::true(str_contains((string) $loaded?->diffText(), 'src/a.php'));
            Assert::same('patch_abc123', $store->findActiveByRun('msn_1', 'run_1')?->patchId());
            Assert::true(count($store->timeline('patch_abc123')) === 1);
            Assert::true(count($store->list('msn_1')) === 1);
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
