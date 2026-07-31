<?php

declare(strict_types=1);

namespace Tests\Infrastructure\MissionControl;

use Aep\Infrastructure\MissionControl\MissionControlKernel;
use Tests\Support\Assert;

final class MissionControlKernelTest
{
    public function test_kernel_bootstraps_admin_and_health(): void
    {
        $root = sys_get_temp_dir() . '/aep_mc_kernel_' . bin2hex(random_bytes(4));
        putenv('AEP_BOOTSTRAP_ADMIN_USERNAME=admin');
        putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD=kernel-secret');
        putenv('AEP_BOOTSTRAP_ADMIN_DISPLAY=Kernel Admin');

        try {
            $kernel = new MissionControlKernel($root, '0.8.0');
            $health = $kernel->health()->probe();
            Assert::same('ok', $health['status']);
            Assert::same('0.8.0', $health['version']);

            $login = $kernel->auth()->login('admin', 'kernel-secret', '2026-07-31T03:00:00Z');
            Assert::same('admin', $login['user']->username());
            Assert::same('admin', $login['user']->role()->toString());

            $dash = $kernel->dashboard()->snapshot();
            Assert::true(isset($dash['activeMissions']));
            Assert::true(isset($dash['waitingApprovals']));
            Assert::same([], $kernel->missions()->list());
            Assert::same([], $kernel->projects()->list());
            Assert::same([], $kernel->approvals()->list());
            Assert::true(isset($kernel->agents()->settings()['enabled']));
            Assert::true(is_array($kernel->agents()->dashboard()));
        } finally {
            $this->removeDir($root);
            putenv('AEP_BOOTSTRAP_ADMIN_USERNAME');
            putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD');
            putenv('AEP_BOOTSTRAP_ADMIN_DISPLAY');
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
