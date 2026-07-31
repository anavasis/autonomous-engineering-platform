<?php

declare(strict_types=1);

namespace Tests\Presentation\MissionControl;

use Aep\Apps\MissionControlApi\HttpKernel;
use Aep\Infrastructure\MissionControl\MissionControlKernel;
use Tests\Support\Assert;

/**
 * Smoke-tests HTTP routing via output buffering (no real socket).
 */
final class HttpKernelSmokeTest
{
    public function test_health_endpoint_json(): void
    {
        require_once dirname(__DIR__, 3) . '/apps/mission-control-api/src/HttpKernel.php';

        $root = sys_get_temp_dir() . '/aep_mc_http_' . bin2hex(random_bytes(4));
        putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD=http-secret');

        $kernel = new MissionControlKernel($root, '0.7.0');
        $http = new HttpKernel($kernel);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/health';
        $_COOKIE = [];

        ob_start();
        $http->handle();
        $raw = ob_get_clean();

        Assert::true(is_string($raw) && $raw !== '');
        $data = json_decode($raw, true);
        Assert::true(is_array($data));
        Assert::same('ok', $data['status'] ?? null);
        Assert::same('0.7.0', $data['version'] ?? null);

        $this->removeDir($root);
        putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD');
    }

    public function test_me_requires_auth(): void
    {
        require_once dirname(__DIR__, 3) . '/apps/mission-control-api/src/HttpKernel.php';

        $root = sys_get_temp_dir() . '/aep_mc_http_' . bin2hex(random_bytes(4));
        $kernel = new MissionControlKernel($root, '0.7.0');
        $http = new HttpKernel($kernel);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/auth/me';
        $_COOKIE = [];

        ob_start();
        $http->handle();
        $raw = ob_get_clean();
        $data = json_decode((string) $raw, true);
        Assert::same(401, $data['status'] ?? null);

        $this->removeDir($root);
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
