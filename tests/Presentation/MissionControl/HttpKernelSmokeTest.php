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

    public function test_runtime_version_env_wins_over_version_file(): void
    {
        $this->loadVersionResolver();

        $tmp = sys_get_temp_dir() . '/aep_ver_' . bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        file_put_contents($tmp . '/VERSION', "9.9.9\n");

        $prev = getenv('AEP_VERSION');
        putenv('AEP_VERSION=2.0.0-override');
        try {
            Assert::same('2.0.0-override', aep_resolve_runtime_version($tmp));
        } finally {
            $this->restoreAepVersion($prev);
            $this->removeDir($tmp);
        }
    }

    public function test_runtime_version_reads_version_file_when_env_absent_or_empty(): void
    {
        $this->loadVersionResolver();

        $tmp = sys_get_temp_dir() . '/aep_ver_' . bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        file_put_contents($tmp . '/VERSION', "1.7.4\n");

        $prev = getenv('AEP_VERSION');
        try {
            putenv('AEP_VERSION');
            Assert::same('1.7.4', aep_resolve_runtime_version($tmp));

            putenv('AEP_VERSION=');
            Assert::same('1.7.4', aep_resolve_runtime_version($tmp));

            putenv('AEP_VERSION=   ');
            Assert::same('1.7.4', aep_resolve_runtime_version($tmp));
        } finally {
            $this->restoreAepVersion($prev);
            $this->removeDir($tmp);
        }
    }

    public function test_runtime_version_fallback_when_no_env_or_file(): void
    {
        $this->loadVersionResolver();

        $tmp = sys_get_temp_dir() . '/aep_ver_' . bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);

        $prev = getenv('AEP_VERSION');
        putenv('AEP_VERSION');
        try {
            Assert::same('0.0.0', aep_resolve_runtime_version($tmp));
        } finally {
            $this->restoreAepVersion($prev);
            $this->removeDir($tmp);
        }
    }

    public function test_api_health_reports_resolved_version_from_resolver(): void
    {
        require_once dirname(__DIR__, 3) . '/apps/mission-control-api/src/HttpKernel.php';
        $this->loadVersionResolver();

        $tmp = sys_get_temp_dir() . '/aep_ver_health_' . bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        file_put_contents($tmp . '/VERSION', "1.7.4\n");

        $prev = getenv('AEP_VERSION');
        putenv('AEP_VERSION');
        putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD=http-secret');

        try {
            $resolved = aep_resolve_runtime_version($tmp);
            Assert::same('1.7.4', $resolved);

            $dataRoot = $tmp . '/data';
            $kernel = new MissionControlKernel($dataRoot, $resolved);
            $http = new HttpKernel($kernel);

            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/api/v1/health';
            $_COOKIE = [];

            ob_start();
            $http->handle();
            $raw = ob_get_clean();
            $data = json_decode((string) $raw, true);
            Assert::true(is_array($data));
            Assert::same('1.7.4', $data['version'] ?? null);
        } finally {
            $this->restoreAepVersion($prev);
            putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD');
            $this->removeDir($tmp);
            $_COOKIE = [];
        }
    }

    public function test_worker_uses_same_version_resolution_as_api(): void
    {
        $this->loadVersionResolver();
        // Loading the worker entrypoint must reuse the same resolver (function_exists guard).
        if (!defined('AEP_TEST_RESOLVER_ONLY')) {
            define('AEP_TEST_RESOLVER_ONLY', true);
        }
        require_once dirname(__DIR__, 3) . '/bin/execution-worker.php';

        $tmp = sys_get_temp_dir() . '/aep_ver_worker_' . bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        file_put_contents($tmp . '/VERSION', "3.1.4\n");

        $prev = getenv('AEP_VERSION');
        try {
            putenv('AEP_VERSION=worker-env-wins');
            Assert::same('worker-env-wins', aep_resolve_runtime_version($tmp));

            putenv('AEP_VERSION');
            Assert::same('3.1.4', aep_resolve_runtime_version($tmp));
        } finally {
            $this->restoreAepVersion($prev);
            $this->removeDir($tmp);
        }
    }

    public function test_deploy_files_do_not_hardcode_release_version(): void
    {
        $repo = dirname(__DIR__, 3);
        $compose = (string) file_get_contents($repo . '/deploy/docker-compose.yml');
        $dockerfile = (string) file_get_contents($repo . '/deploy/Dockerfile.api');
        $envExample = (string) file_get_contents($repo . '/deploy/.env.example');

        Assert::true(!preg_match('/AEP_VERSION:\s*"\d+\.\d+\.\d+"/', $compose));
        Assert::true(!preg_match('/^ENV\s+AEP_VERSION=/m', $dockerfile));
        Assert::true(str_contains($dockerfile, 'COPY VERSION ./VERSION'));
        Assert::true(str_contains($compose, 'AEP_VERSION: ${AEP_VERSION:-}'));
        Assert::true(!preg_match('/^AEP_VERSION=\d+\.\d+\.\d+\s*$/m', $envExample));
        Assert::true(str_contains($envExample, 'VERSION file is canonical') || str_contains($envExample, 'canonical'));
    }

    public function test_compose_mounts_execution_providers_catalog_for_api_and_worker(): void
    {
        $repo = dirname(__DIR__, 3);
        $compose = (string) file_get_contents($repo . '/deploy/docker-compose.yml');
        $envExample = (string) file_get_contents($repo . '/deploy/.env.example');

        $mount = './execution-providers.json:/var/www/aep/deploy/execution-providers.json:ro';
        $envLine = 'AEP_EXECUTION_PROVIDERS_CONFIG: /var/www/aep/deploy/execution-providers.json';
        Assert::true(substr_count($compose, $mount) === 2);
        Assert::true(substr_count($compose, $envLine) === 2);
        Assert::true(str_contains($envExample, 'AEP_EXECUTION_PROVIDERS_CONFIG=/var/www/aep/deploy/execution-providers.json'));
        Assert::true(str_contains($envExample, 'not a secret') || str_contains($envExample, 'not a secret'));
        Assert::true(!str_contains($compose, 'OPENAI_API_KEY'));
        Assert::true(!str_contains($compose, 'CODEX_API_KEY'));
        Assert::true(!str_contains($compose, 'ANTHROPIC_API_KEY'));
        Assert::true(!str_contains($compose, 'GEMINI_API_KEY'));
    }

    public function test_dockerfile_pins_and_verifies_codex_musl_binary(): void
    {
        $repo = dirname(__DIR__, 3);
        $dockerfile = (string) file_get_contents($repo . '/deploy/Dockerfile.api');

        Assert::true(str_contains($dockerfile, 'CODEX_VERSION=0.145.0'));
        Assert::true(str_contains($dockerfile, 'codex-x86_64-unknown-linux-musl.tar.gz'));
        Assert::true(str_contains($dockerfile, 'CODEX_SHA256='));
        Assert::true(str_contains($dockerfile, 'sha256sum -c'));
        Assert::true(str_contains($dockerfile, '/usr/local/bin/codex'));
        Assert::true(str_contains($dockerfile, 'codex --version'));
        Assert::true(str_contains($dockerfile, 'bubblewrap'));
        Assert::true(str_contains($dockerfile, 'command -v bwrap'));
        Assert::true(str_contains($dockerfile, 'bwrap --version'));
        Assert::true(!str_contains($dockerfile, 'npm install'));
        Assert::true(!str_contains($dockerfile, 'nodejs'));
        Assert::true(!preg_match('/curl\s+[^\n]*\|\s*sh/', $dockerfile));
        Assert::true(!str_contains($dockerfile, 'OPENAI_API_KEY'));
        Assert::true(!str_contains($dockerfile, 'CODEX_API_KEY'));
        Assert::true(!str_contains($dockerfile, 'auth.json'));
        Assert::true(!str_contains($dockerfile, '.codex'));
    }

    public function test_compose_worker_has_persistent_codex_home_not_on_proxy(): void
    {
        $repo = dirname(__DIR__, 3);
        $compose = (string) file_get_contents($repo . '/deploy/docker-compose.yml');
        $envExample = (string) file_get_contents($repo . '/deploy/.env.example');

        Assert::true(str_contains($compose, 'HOME: /var/aep/codex-home'));
        Assert::true(str_contains($compose, 'codex_home:/var/aep/codex-home'));
        Assert::true(preg_match('/^  codex_home:\s*$/m', $compose) === 1);

        // Proxy block must not reference Codex credentials volume.
        if (preg_match('/^  proxy:\n(.*?)^  api:/ms', $compose, $m) === 1) {
            Assert::true(!str_contains($m[1], 'codex_home'));
            Assert::true(!str_contains($m[1], 'codex-home'));
        } else {
            Assert::true(false, 'Unable to isolate proxy service block.');
        }

        // API does not need credential mount for binary health.
        if (preg_match('/^  api:\n(.*?)^  worker:/ms', $compose, $m) === 1) {
            Assert::true(!str_contains($m[1], 'codex_home:'));
            Assert::true(!str_contains($m[1], 'HOME: /var/aep/codex-home'));
        } else {
            Assert::true(false, 'Unable to isolate api service block.');
        }

        Assert::true(str_contains($envExample, '/var/aep/codex-home'));
        Assert::true(str_contains($envExample, 'docker compose run'));
        Assert::true(str_contains($envExample, 'never be committed') || str_contains($envExample, 'must never be committed'));

        $catalog = json_decode((string) file_get_contents($repo . '/deploy/execution-providers.json'), true);
        Assert::true(is_array($catalog));
        $ids = array_map(static fn (array $p): string => (string) ($p['id'] ?? ''), $catalog['providers'] ?? []);
        Assert::true(in_array('codex', $ids, true));
    }

    public function test_compose_relaxes_seccomp_and_apparmor_for_worker_only(): void
    {
        $repo = dirname(__DIR__, 3);
        $compose = (string) file_get_contents($repo . '/deploy/docker-compose.yml');

        Assert::true(preg_match('/^  proxy:\n(.*?)^  api:/ms', $compose, $proxyMatch) === 1);
        Assert::true(preg_match('/^  api:\n(.*?)^  worker:/ms', $compose, $apiMatch) === 1);
        Assert::true(preg_match('/^  worker:\n(.*?)^volumes:/ms', $compose, $workerMatch) === 1);

        $proxy = $proxyMatch[1];
        $api = $apiMatch[1];
        $worker = $workerMatch[1];

        Assert::true(str_contains($worker, 'security_opt:'));
        Assert::true(str_contains($worker, 'seccomp=unconfined'));
        Assert::true(str_contains($worker, 'apparmor=unconfined'));
        Assert::same(1, substr_count($compose, 'seccomp=unconfined'));
        Assert::same(1, substr_count($compose, 'apparmor=unconfined'));

        Assert::true(!str_contains($proxy, 'security_opt'));
        Assert::true(!str_contains($proxy, 'seccomp=unconfined'));
        Assert::true(!str_contains($proxy, 'apparmor=unconfined'));

        Assert::true(!str_contains($api, 'security_opt'));
        Assert::true(!str_contains($api, 'seccomp=unconfined'));
        Assert::true(!str_contains($api, 'apparmor=unconfined'));

        Assert::true(!str_contains($worker, 'privileged: true'));
        Assert::true(!str_contains($worker, 'cap_add:'));
        Assert::true(!str_contains($worker, 'CAP_SYS_ADMIN'));
        Assert::true(!str_contains($worker, '/var/run/docker.sock'));
        Assert::true(!str_contains($worker, 'user: root'));
        Assert::true(!str_contains($worker, 'pid: host'));
        Assert::true(!str_contains($worker, 'network_mode: host'));

        Assert::true(str_contains($worker, 'HOME: /var/aep/codex-home'));
        Assert::true(str_contains($worker, 'aep_data:/var/aep/data'));
        Assert::true(str_contains($worker, 'codex_home:/var/aep/codex-home'));
    }

    public function test_execution_providers_catalog_lists_cli_providers_even_when_unavailable(): void
    {
        require_once dirname(__DIR__, 3) . '/apps/mission-control-api/src/HttpKernel.php';

        $repo = dirname(__DIR__, 3);
        $catalog = $repo . '/deploy/execution-providers.json';
        Assert::true(is_file($catalog));

        $root = sys_get_temp_dir() . '/aep_mc_providers_' . bin2hex(random_bytes(4));
        $prevConfig = getenv('AEP_EXECUTION_PROVIDERS_CONFIG');
        putenv('AEP_EXECUTION_PROVIDERS_CONFIG=' . $catalog);
        putenv('AEP_BOOTSTRAP_ADMIN_USERNAME=admin');
        putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD=providers-secret');

        try {
            $kernel = new MissionControlKernel($root, '1.7.5');
            $listed = $kernel->execution()->listProviders();
            $ids = array_map(static fn (array $p): string => (string) ($p['id'] ?? ''), $listed);
            foreach (['local-agent', 'cursor', 'claude-code', 'codex', 'gemini-cli', 'legacy-local'] as $expectedId) {
                Assert::true(in_array($expectedId, $ids, true), 'missing provider id: ' . $expectedId);
            }

            $byId = [];
            foreach ($listed as $item) {
                $byId[(string) $item['id']] = $item;
            }
            foreach (['cursor', 'claude-code', 'codex', 'gemini-cli'] as $cliId) {
                Assert::true(isset($byId[$cliId]));
                $health = $byId[$cliId]['health'] ?? null;
                Assert::true(is_array($health));
                // Binaries are not installed in CI — must still be listed (no health filter).
                Assert::true(in_array((string) ($health['status'] ?? ''), ['ok', 'degraded', 'unavailable'], true));
            }

            $login = $kernel->auth()->login('admin', 'providers-secret', Utc::now());
            $sid = $login['session']->sessionId();
            $http = new HttpKernel($kernel);

            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/api/v1/execution/providers';
            $_COOKIE = ['aep_session' => $sid];
            ob_start();
            $http->handle();
            $providersBody = json_decode((string) ob_get_clean(), true);
            Assert::true(is_array($providersBody));
            $httpIds = array_map(
                static fn (array $p): string => (string) ($p['id'] ?? ''),
                is_array($providersBody['items'] ?? null) ? $providersBody['items'] : []
            );
            foreach (['cursor', 'claude-code', 'codex', 'gemini-cli'] as $expectedId) {
                Assert::true(in_array($expectedId, $httpIds, true));
            }

            $_SERVER['REQUEST_URI'] = '/api/v1/settings/execution';
            ob_start();
            $http->handle();
            $settingsBody = json_decode((string) ob_get_clean(), true);
            Assert::true(is_array($settingsBody));
            $settingsProviders = is_array($settingsBody['providers'] ?? null) ? $settingsBody['providers'] : [];
            $settingsIds = array_map(static fn (array $p): string => (string) ($p['id'] ?? ''), $settingsProviders);
            foreach (['cursor', 'claude-code', 'codex', 'gemini-cli'] as $expectedId) {
                Assert::true(in_array($expectedId, $settingsIds, true));
            }
            $default = $settingsBody['settings']['defaultProviderId'] ?? null;
            Assert::true($default === null || $default === '');
        } finally {
            if ($prevConfig === false) {
                putenv('AEP_EXECUTION_PROVIDERS_CONFIG');
            } else {
                putenv('AEP_EXECUTION_PROVIDERS_CONFIG=' . $prevConfig);
            }
            putenv('AEP_BOOTSTRAP_ADMIN_USERNAME');
            putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD');
            $_COOKIE = [];
            $this->removeDir($root);
        }
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

    private function loadVersionResolver(): void
    {
        if (!defined('AEP_TEST_RESOLVER_ONLY')) {
            define('AEP_TEST_RESOLVER_ONLY', true);
        }
        require_once dirname(__DIR__, 3) . '/apps/mission-control-api/public/index.php';
        Assert::true(function_exists('aep_resolve_runtime_version'));
    }

    private function restoreAepVersion(string|false $prev): void
    {
        if ($prev === false) {
            putenv('AEP_VERSION');
        } else {
            putenv('AEP_VERSION=' . $prev);
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
