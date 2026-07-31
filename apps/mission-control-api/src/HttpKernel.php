<?php

declare(strict_types=1);

namespace Aep\Apps\MissionControlApi;

use Aep\Application\MissionControl\Auth\AuthException;
use Aep\Application\MissionControl\Auth\AuthService;
use Aep\Application\MissionControl\Auth\Role;
use Aep\Application\MissionControl\Auth\User;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Infrastructure\MissionControl\Http\JsonResponse;
use Aep\Infrastructure\MissionControl\MissionControlKernel;

/**
 * Mission Control HTTP front controller (presentation adapter).
 */
final class HttpKernel
{
    public function __construct(
        private readonly MissionControlKernel $app,
    ) {
    }

    public function handle(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($uri) ? $uri : '/';
        $path = rtrim($path, '/') ?: '/';

        if (str_starts_with($path, '/api/v1')) {
            $path = substr($path, strlen('/api/v1')) ?: '/';
        }

        try {
            $this->route($method, $path);
        } catch (AuthException $e) {
            JsonResponse::problem($e->getMessage(), $e->statusCode(), $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            JsonResponse::problem('Bad Request', 400, $e->getMessage());
        } catch (\Throwable $e) {
            JsonResponse::problem('Internal Server Error', 500, $e->getMessage());
        }
    }

    private function route(string $method, string $path): void
    {
        if ($method === 'GET' && $path === '/health') {
            JsonResponse::send($this->app->health()->probe());

            return;
        }

        if ($method === 'POST' && $path === '/auth/login') {
            $this->login();

            return;
        }

        if ($method === 'POST' && $path === '/auth/logout') {
            $user = $this->requireUser();
            unset($user);
            $sid = $this->sessionIdFromRequest();
            if ($sid !== null) {
                $this->app->auth()->logout($sid);
            }
            $this->clearSessionCookie();
            JsonResponse::send(['ok' => true]);

            return;
        }

        if ($method === 'GET' && $path === '/auth/me') {
            $user = $this->requireUser();
            $session = $this->requireSession();
            JsonResponse::send([
                'user' => $user->toPublicArray(),
                'csrfToken' => $session->csrfToken(),
            ]);

            return;
        }

        $user = $this->requireUser();

        if ($method === 'GET' && $path === '/dashboard') {
            JsonResponse::send($this->app->dashboard()->snapshot());

            return;
        }

        if ($method === 'GET' && $path === '/projects') {
            JsonResponse::send(['items' => $this->app->projects()->list()]);

            return;
        }

        if ($method === 'POST' && $path === '/projects') {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            $id = is_string($body['id'] ?? null) ? $body['id'] : 'proj_' . bin2hex(random_bytes(6));
            $slug = is_string($body['slug'] ?? null) && $body['slug'] !== ''
                ? $body['slug']
                : 'project-' . bin2hex(random_bytes(4));
            $result = $this->app->commands()->createProject(
                $user,
                $id,
                $slug,
                (string) ($body['displayName'] ?? $id),
                (string) ($body['description'] ?? '')
            );
            JsonResponse::send($result, 201);

            return;
        }

        if ($method === 'GET' && preg_match('#^/projects/([A-Za-z0-9_-]+)$#', $path, $m) === 1) {
            $item = $this->app->projects()->get($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Project not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && $path === '/missions') {
            $state = isset($_GET['state']) && is_string($_GET['state']) ? $_GET['state'] : null;
            JsonResponse::send(['items' => $this->app->missions()->list($state)]);

            return;
        }

        if ($method === 'POST' && $path === '/missions') {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            $id = is_string($body['id'] ?? null) ? $body['id'] : 'msn_' . bin2hex(random_bytes(6));
            $result = $this->app->commands()->createMission(
                $user,
                $id,
                (string) ($body['provider'] ?? 'github'),
                (string) ($body['repository'] ?? ''),
                (string) ($body['objective'] ?? '')
            );
            JsonResponse::send($result, 201);

            return;
        }

        if ($method === 'GET' && preg_match('#^/missions/([A-Za-z0-9_-]+)$#', $path, $m) === 1) {
            $item = $this->app->missions()->get($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Mission not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && preg_match('#^/missions/([A-Za-z0-9_-]+)/timeline$#', $path, $m) === 1) {
            $runId = isset($_GET['runId']) && is_string($_GET['runId']) ? $_GET['runId'] : null;
            JsonResponse::send(['items' => $this->app->missions()->timeline($m[1], $runId)]);

            return;
        }

        if ($method === 'POST' && preg_match('#^/missions/([A-Za-z0-9_-]+)/runs$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            $runId = is_string($body['runId'] ?? null) ? $body['runId'] : 'run_' . bin2hex(random_bytes(6));
            $projectId = isset($body['projectId']) && is_string($body['projectId']) ? $body['projectId'] : null;
            $attributes = isset($body['attributes']) && is_array($body['attributes']) ? $body['attributes'] : [];
            JsonResponse::send(
                $this->app->commands()->startRun($user, $runId, $m[1], $projectId, $attributes),
                201
            );

            return;
        }

        if ($method === 'POST' && preg_match('#^/missions/([A-Za-z0-9_-]+)/runs/([A-Za-z0-9_-]+)/cancel$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            JsonResponse::send($this->app->commands()->cancelRun($m[2]));

            return;
        }

        if ($method === 'GET' && $path === '/approvals') {
            JsonResponse::send(['items' => $this->app->approvals()->list()]);

            return;
        }

        if ($method === 'POST' && preg_match('#^/approvals/([A-Za-z0-9_-]+)/inspection$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::APPROVER);
            $body = $this->jsonBody();
            $approve = ($body['decision'] ?? '') === 'approved';
            JsonResponse::send($this->app->commands()->decideInspection(
                $user,
                $m[1],
                $approve,
                (string) ($body['rationale'] ?? '')
            ));

            return;
        }

        if ($method === 'POST' && preg_match('#^/approvals/([A-Za-z0-9_-]+)/commit$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::APPROVER);
            $body = $this->jsonBody();
            $approve = ($body['decision'] ?? '') === 'approved';
            JsonResponse::send($this->app->commands()->decideCommit(
                $user,
                $m[1],
                $approve,
                (string) ($body['rationale'] ?? '')
            ));

            return;
        }

        if ($method === 'POST' && preg_match('#^/approvals/([A-Za-z0-9_-]+)/gates/([A-Za-z0-9_.-]+)$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::APPROVER);
            $body = $this->jsonBody();
            $runId = (string) ($body['runId'] ?? '');
            if ($runId === '') {
                throw new \InvalidArgumentException('runId is required for gate decisions.');
            }
            $approve = ($body['decision'] ?? '') === 'approved';
            JsonResponse::send($this->app->commands()->decideGate($user, $runId, $m[2], $approve));

            return;
        }

        if ($method === 'GET' && $path === '/artifacts') {
            $missionId = isset($_GET['missionId']) && is_string($_GET['missionId']) ? $_GET['missionId'] : null;
            JsonResponse::send(['items' => $this->app->artifacts()->listWorkspaces($missionId)]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/artifacts/([A-Za-z0-9_-]+)$#', $path, $m) === 1) {
            $item = $this->app->artifacts()->workspace($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Workspace not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && preg_match('#^/artifacts/([A-Za-z0-9_-]+)/items/([a-z0-9.]+)/content$#', $path, $m) === 1) {
            $item = $this->app->artifacts()->content($m[1], $m[2]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Artifact not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && $path === '/validation') {
            JsonResponse::send(['items' => $this->app->validation()->list()]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/validation/([A-Za-z0-9_-]+)$#', $path, $m) === 1) {
            $item = $this->app->validation()->forMission($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Mission not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && $path === '/settings') {
            JsonResponse::send([
                'product' => 'AEP Mission Control',
                'version' => '0.1.0',
                'deployment' => 'aep.anavasis.tech',
                'pollIntervalSeconds' => 5,
                'features' => [
                    'assignedAgent' => false,
                    'sse' => false,
                ],
                'health' => $this->app->health()->probe(),
                'user' => $user->toPublicArray(),
            ]);

            return;
        }

        JsonResponse::problem('Not Found', 404, 'No route for ' . $method . ' ' . $path);
    }

    private function login(): void
    {
        $body = $this->jsonBody();
        $username = (string) ($body['username'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $result = $this->app->auth()->login($username, $password, Utc::now());
        $this->setSessionCookie($result['session']->sessionId());
        JsonResponse::send([
            'user' => $result['user']->toPublicArray(),
            'csrfToken' => $result['session']->csrfToken(),
        ]);
    }

    private function requireUser(): User
    {
        $sid = $this->sessionIdFromRequest();
        if ($sid === null) {
            throw new AuthException('Authentication required.', 401);
        }
        $user = $this->app->auth()->resolveSession($sid, Utc::now());
        if ($user === null) {
            $this->clearSessionCookie();
            throw new AuthException('Authentication required.', 401);
        }

        return $user;
    }

    private function requireSession(): \Aep\Application\MissionControl\Auth\SessionRecord
    {
        $sid = $this->sessionIdFromRequest();
        if ($sid === null) {
            throw new AuthException('Authentication required.', 401);
        }
        $session = $this->app->auth()->session($sid);
        if ($session === null) {
            throw new AuthException('Authentication required.', 401);
        }

        return $session;
    }

    private function requireCsrf(): void
    {
        $session = $this->requireSession();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || $token === '' || !hash_equals($session->csrfToken(), $token)) {
            throw new AuthException('Invalid CSRF token.', 403);
        }
    }

    private function sessionIdFromRequest(): ?string
    {
        $cookie = $_COOKIE[AuthService::COOKIE_NAME] ?? null;
        if (!is_string($cookie) || $cookie === '') {
            return null;
        }

        return $cookie;
    }

    private function setSessionCookie(string $sessionId): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie(AuthService::COOKIE_NAME, $sessionId, [
            'expires' => time() + AuthService::DEFAULT_TTL_SECONDS,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[AuthService::COOKIE_NAME] = $sessionId;
    }

    private function clearSessionCookie(): void
    {
        setcookie(AuthService::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[AuthService::COOKIE_NAME]);
    }

    /** @return array<string, mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Request body must be valid JSON.', 0, $e);
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException('JSON body must be an object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
