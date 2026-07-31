<?php

declare(strict_types=1);

namespace Tests\Application\MissionControl;

use Aep\Application\MissionControl\Auth\AuthException;
use Aep\Application\MissionControl\Auth\AuthService;
use Aep\Application\MissionControl\Auth\Role;
use Aep\Infrastructure\MissionControl\Auth\FileSessionStore;
use Aep\Infrastructure\MissionControl\Auth\JsonUserStore;
use Tests\Support\Assert;

final class AuthServiceTest
{
    public function test_bootstrap_login_and_session_roundtrip(): void
    {
        $root = sys_get_temp_dir() . '/aep_mc_auth_' . bin2hex(random_bytes(4));
        mkdir($root . '/sessions', 0777, true);
        try {
            $auth = new AuthService(
                new JsonUserStore($root . '/users.json'),
                new FileSessionStore($root . '/sessions'),
                3600
            );

            $created = $auth->ensureBootstrapAdmin('admin', 'secret-pass', 'Admin', '2026-07-31T02:00:00Z');
            Assert::true($created !== null);
            Assert::same(Role::ADMIN, $created->role()->toString());
            Assert::true($auth->ensureBootstrapAdmin('admin', 'x', 'Admin', '2026-07-31T02:00:01Z') === null);

            $login = $auth->login('admin', 'secret-pass', '2026-07-31T02:01:00Z');
            Assert::same('admin', $login['user']->username());
            Assert::true($login['session']->csrfToken() !== '');

            $resolved = $auth->resolveSession($login['session']->sessionId(), '2026-07-31T02:02:00Z');
            Assert::true($resolved !== null);
            Assert::same('admin', $resolved->username());

            $auth->assertRole($resolved, Role::APPROVER);

            $auth->logout($login['session']->sessionId());
            Assert::true($auth->resolveSession($login['session']->sessionId(), '2026-07-31T02:03:00Z') === null);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_invalid_login_rejected(): void
    {
        $root = sys_get_temp_dir() . '/aep_mc_auth_' . bin2hex(random_bytes(4));
        mkdir($root . '/sessions', 0777, true);
        try {
            $auth = new AuthService(
                new JsonUserStore($root . '/users.json'),
                new FileSessionStore($root . '/sessions')
            );
            $auth->ensureBootstrapAdmin('admin', 'secret-pass', 'Admin', '2026-07-31T02:00:00Z');
            try {
                $auth->login('admin', 'wrong', '2026-07-31T02:01:00Z');
                Assert::true(false, 'Expected AuthException');
            } catch (AuthException $e) {
                Assert::same(401, $e->statusCode());
            }
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
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
