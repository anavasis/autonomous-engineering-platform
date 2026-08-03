<?php

declare(strict_types=1);

namespace Tests\Presentation\MissionControl;

use Aep\Application\EngineeringExecution\Model\ExecutionSession;
use Aep\Application\EngineeringExecution\Model\ProviderEvent;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Apps\MissionControlApi\HttpKernel;
use Aep\Infrastructure\EngineeringExecution\Store\JsonExecutionSessionStore;
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

        $kernel = new MissionControlKernel($root, '1.0.0');
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
        Assert::same('1.0.0', $data['version'] ?? null);

        $this->removeDir($root);
        putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD');
    }

    public function test_me_requires_auth(): void
    {
        require_once dirname(__DIR__, 3) . '/apps/mission-control-api/src/HttpKernel.php';

        $root = sys_get_temp_dir() . '/aep_mc_http_' . bin2hex(random_bytes(4));
        $kernel = new MissionControlKernel($root, '1.0.0');
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

    public function test_execution_events_stream_requires_auth_and_streams_when_authed(): void
    {
        require_once dirname(__DIR__, 3) . '/apps/mission-control-api/src/HttpKernel.php';

        $root = sys_get_temp_dir() . '/aep_mc_sse_' . bin2hex(random_bytes(4));
        putenv('AEP_BOOTSTRAP_ADMIN_USERNAME=admin');
        putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD=sse-secret');
        putenv('AEP_SSE_ENABLED=true');

        $kernel = new MissionControlKernel($root, '1.6.0');
        $http = new HttpKernel($kernel);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/execution/sessions/esess_missing/events/stream';
        $_COOKIE = [];
        ob_start();
        $http->handle();
        $unauth = ob_get_clean();
        $unauthData = json_decode((string) $unauth, true);
        Assert::same(401, $unauthData['status'] ?? null);

        $login = $kernel->auth()->login('admin', 'sse-secret', Utc::now());
        $sid = $login['session']->sessionId();

        $store = new JsonExecutionSessionStore($root . '/execution');
        $store->save(new ExecutionSession(
            'esess_sse_http',
            'msn_sse_http',
            'run_sse_http',
            'local-agent',
            ExecutionSession::STATUS_SUCCEEDED,
            Utc::now(),
            Utc::now(),
            'done',
        ));
        $store->appendEvent('esess_sse_http', new ProviderEvent(1, 'log', 'stream-line', Utc::now()));
        $store->appendEvent('esess_sse_http', new ProviderEvent(2, 'provider.completed', 'ok', Utc::now()));

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/execution/sessions/esess_sse_http/events/stream?afterSeq=0';
        $_COOKIE = ['aep_session' => $sid];
        unset($_SERVER['HTTP_LAST_EVENT_ID']);

        ob_start();
        $http->handle();
        $raw = (string) ob_get_clean();
        Assert::true(str_contains($raw, 'event: execution'));
        Assert::true(str_contains($raw, 'stream-line'));
        Assert::true(str_contains($raw, 'event: done'));

        // Last-Event-ID resume skips already-seen seq.
        $_SERVER['HTTP_LAST_EVENT_ID'] = '1';
        $_SERVER['REQUEST_URI'] = '/api/v1/execution/sessions/esess_sse_http/events/stream';
        ob_start();
        $http->handle();
        $resumed = (string) ob_get_clean();
        Assert::true(!str_contains($resumed, '"seq":1'));
        Assert::true(str_contains($resumed, '"seq":2') || str_contains($resumed, 'event: done'));

        $this->removeDir($root);
        putenv('AEP_BOOTSTRAP_ADMIN_USERNAME');
        putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD');
        putenv('AEP_SSE_ENABLED');
        unset($_SERVER['HTTP_LAST_EVENT_ID']);
        $_COOKIE = [];
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
