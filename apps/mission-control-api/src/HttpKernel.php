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

        if ($method === 'POST' && $path === '/missions/intake') {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            $text = (string) ($body['text'] ?? '');
            if (trim($text) === '') {
                throw new \InvalidArgumentException('text is required.');
            }
            $projectId = isset($body['projectId']) && is_string($body['projectId']) ? $body['projectId'] : null;
            $clientRequestId = isset($body['clientRequestId']) && is_string($body['clientRequestId']) ? $body['clientRequestId'] : null;
            JsonResponse::send($this->app->ame()->intake($user, $text, $projectId, $clientRequestId), 201);

            return;
        }

        if ($method === 'GET' && preg_match('#^/missions/intake/([A-Za-z0-9_-]+)$#', $path, $m) === 1) {
            $item = $this->app->ame()->getIntake($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Intake not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'POST' && preg_match('#^/missions/intake/([A-Za-z0-9_-]+)/clarify$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            $answers = isset($body['answers']) && is_array($body['answers']) ? $body['answers'] : $body;
            JsonResponse::send($this->app->ame()->clarify($user, $m[1], $answers));

            return;
        }

        if ($method === 'GET' && preg_match('#^/missions/intake/([A-Za-z0-9_-]+)/plan$#', $path, $m) === 1) {
            JsonResponse::send($this->app->ame()->preview($m[1]));

            return;
        }

        if ($method === 'POST' && preg_match('#^/missions/intake/([A-Za-z0-9_-]+)/start$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            $confirmed = ($body['confirmed'] ?? false) === true;
            JsonResponse::send($this->app->ame()->confirmAndLaunch($user, $m[1], $confirmed), 201);

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

        if ($method === 'POST' && preg_match('#^/missions/([A-Za-z0-9_-]+)/runs/([A-Za-z0-9_-]+)/resume$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            $attributes = isset($body['attributes']) && is_array($body['attributes']) ? $body['attributes'] : [];
            JsonResponse::send($this->app->ame()->resume($m[2], $attributes));

            return;
        }

        if ($method === 'POST' && preg_match('#^/missions/([A-Za-z0-9_-]+)/retry$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            JsonResponse::send($this->app->ame()->retry($user, $m[1]));

            return;
        }

        if ($method === 'GET' && preg_match('#^/missions/([A-Za-z0-9_-]+)/conversation$#', $path, $m) === 1) {
            $item = $this->app->ame()->conversationForMission($m[1]);
            JsonResponse::send(['conversation' => $item]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/missions/([A-Za-z0-9_-]+)/plan$#', $path, $m) === 1) {
            $item = $this->app->ame()->planForMission($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Execution plan not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && preg_match('#^/projects/([A-Za-z0-9_-]+)/memory$#', $path, $m) === 1) {
            $item = $this->app->ame()->projectMemory($m[1]);
            JsonResponse::send(['memory' => $item]);

            return;
        }

        if ($method === 'POST' && preg_match('#^/projects/([A-Za-z0-9_-]+)/memory/capture$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            $this->app->ame()->captureMemory(
                $m[1],
                (string) ($body['objective'] ?? ''),
                ($body['succeeded'] ?? true) === true,
                isset($body['constraints']) && is_array($body['constraints']) ? $body['constraints'] : []
            );
            JsonResponse::send(['ok' => true]);

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
                'version' => '0.6.0',
                'deployment' => 'aep.anavasis.tech',
                'pollIntervalSeconds' => 5,
                'features' => [
                    'assignedAgent' => false,
                    'sse' => false,
                    'autonomousMissionExecution' => true,
                    'engineeringExecutionProviders' => true,
                    'engineeringWorkspaces' => true,
                    'codeReviewPatchPipeline' => true,
                    'engineeringKnowledgeMemory' => true,
                ],
                'health' => $this->app->health()->probe(),
                'user' => $user->toPublicArray(),
                'execution' => $this->app->execution()->settings(),
                'workspaces' => $this->app->workspaces()->settings(),
                'patches' => $this->app->patches()->settings(),
                'knowledge' => $this->app->knowledge()->settings(),
            ]);

            return;
        }

        if ($method === 'GET' && $path === '/settings/patches') {
            JsonResponse::send([
                'settings' => $this->app->patches()->settings(),
                'reviewProviders' => $this->app->patches()->listReviewProviders(),
            ]);

            return;
        }

        if ($method === 'PUT' && $path === '/settings/patches') {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            JsonResponse::send(['settings' => $this->app->patches()->updateSettings($body)]);

            return;
        }

        if ($method === 'GET' && $path === '/settings/knowledge') {
            JsonResponse::send([
                'settings' => $this->app->knowledge()->settings(),
                'embeddingProviders' => $this->app->knowledge()->listEmbeddingProviders(),
            ]);

            return;
        }

        if ($method === 'PUT' && $path === '/settings/knowledge') {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            JsonResponse::send(['settings' => $this->app->knowledge()->updateSettings($body)]);

            return;
        }

        if ($method === 'GET' && $path === '/knowledge') {
            $projectId = isset($_GET['projectId']) && is_string($_GET['projectId']) ? $_GET['projectId'] : null;
            $kind = isset($_GET['kind']) && is_string($_GET['kind']) ? $_GET['kind'] : null;
            $status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : null;
            $q = isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : null;
            JsonResponse::send(['items' => $this->app->knowledge()->list($projectId, $kind, $status, $q)]);

            return;
        }

        if ($method === 'GET' && $path === '/knowledge/timeline') {
            JsonResponse::send(['items' => $this->app->knowledge()->timeline(null, 100)]);

            return;
        }

        if ($method === 'GET' && $path === '/knowledge/lessons') {
            $projectId = isset($_GET['projectId']) && is_string($_GET['projectId']) ? $_GET['projectId'] : null;
            JsonResponse::send(['items' => $this->app->knowledge()->lessons($projectId)]);

            return;
        }

        if ($method === 'GET' && $path === '/knowledge/similar/missions') {
            $objective = isset($_GET['objective']) && is_string($_GET['objective']) ? $_GET['objective'] : '';
            $projectId = isset($_GET['projectId']) && is_string($_GET['projectId']) ? $_GET['projectId'] : null;
            JsonResponse::send([
                'items' => $this->app->knowledge()->retrieval()->similarMissions($objective, $projectId),
            ]);

            return;
        }

        if ($method === 'GET' && $path === '/knowledge/similar/patches') {
            $objective = isset($_GET['objective']) && is_string($_GET['objective']) ? $_GET['objective'] : '';
            $projectId = isset($_GET['projectId']) && is_string($_GET['projectId']) ? $_GET['projectId'] : null;
            JsonResponse::send([
                'items' => $this->app->knowledge()->retrieval()->similarPatches($objective, $projectId),
            ]);

            return;
        }

        if ($method === 'POST' && $path === '/knowledge/retrieve') {
            $this->requireCsrf();
            $body = $this->jsonBody();
            JsonResponse::send(['pack' => $this->app->knowledge()->retrievePreview($body)]);

            return;
        }

        if ($method === 'POST' && $path === '/knowledge/capture') {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            JsonResponse::send(['knowledge' => $this->app->knowledge()->captureManual($body, $user->username())]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/knowledge/([A-Za-z0-9_-]+)$#', $path, $m) === 1) {
            $item = $this->app->knowledge()->get($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Knowledge not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && preg_match('#^/knowledge/([A-Za-z0-9_-]+)/timeline$#', $path, $m) === 1) {
            JsonResponse::send(['items' => $this->app->knowledge()->timeline($m[1])]);

            return;
        }

        if ($method === 'POST' && preg_match('#^/knowledge/([A-Za-z0-9_-]+)/archive$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $item = $this->app->knowledge()->archive($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Knowledge not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'POST' && preg_match('#^/knowledge/([A-Za-z0-9_-]+)/forget$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $item = $this->app->knowledge()->forget($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Knowledge not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && preg_match('#^/missions/([A-Za-z0-9_-]+)/memory$#', $path, $m) === 1) {
            JsonResponse::send(['items' => $this->app->knowledge()->missionMemory($m[1])]);

            return;
        }

        if ($method === 'POST' && preg_match('#^/missions/([A-Za-z0-9_-]+)/memory/retrieve$#', $path, $m) === 1) {
            $this->requireCsrf();
            $body = $this->jsonBody();
            $body['missionId'] = $m[1];
            if (!isset($body['objective']) || !is_string($body['objective']) || $body['objective'] === '') {
                $body['objective'] = 'mission ' . $m[1];
            }
            JsonResponse::send(['pack' => $this->app->knowledge()->retrievePreview($body)]);

            return;
        }

        if ($method === 'GET' && $path === '/patches') {
            $missionId = isset($_GET['missionId']) && is_string($_GET['missionId']) ? $_GET['missionId'] : null;
            $status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : null;
            $mergeReady = null;
            if (isset($_GET['mergeReady'])) {
                $mergeReady = $_GET['mergeReady'] === '1' || $_GET['mergeReady'] === 'true';
            }
            JsonResponse::send(['items' => $this->app->patches()->list($missionId, $status, $mergeReady)]);

            return;
        }

        if ($method === 'GET' && $path === '/reviews/queue') {
            JsonResponse::send(['items' => $this->app->patches()->reviewQueue()]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/patches/([A-Za-z0-9_-]+)$#', $path, $m) === 1) {
            $item = $this->app->patches()->get($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Patch not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && preg_match('#^/patches/([A-Za-z0-9_-]+)/diff$#', $path, $m) === 1) {
            $item = $this->app->patches()->diff($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Patch not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && preg_match('#^/patches/([A-Za-z0-9_-]+)/manifest$#', $path, $m) === 1) {
            $item = $this->app->patches()->manifest($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Patch not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && preg_match('#^/patches/([A-Za-z0-9_-]+)/checks$#', $path, $m) === 1) {
            JsonResponse::send(['items' => $this->app->patches()->checks($m[1])]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/patches/([A-Za-z0-9_-]+)/reviews$#', $path, $m) === 1) {
            JsonResponse::send(['items' => $this->app->patches()->reviews($m[1])]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/patches/([A-Za-z0-9_-]+)/timeline$#', $path, $m) === 1) {
            JsonResponse::send(['items' => $this->app->patches()->timeline($m[1])]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/patches/([A-Za-z0-9_-]+)/readiness$#', $path, $m) === 1) {
            $item = $this->app->patches()->readiness($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Patch not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'POST' && $path === '/patches') {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            $missionId = (string) ($body['missionId'] ?? '');
            $runId = (string) ($body['runId'] ?? 'run_manual');
            $diff = (string) ($body['diff'] ?? '');
            if ($missionId === '' || $diff === '') {
                throw new \InvalidArgumentException('missionId and diff are required.');
            }
            $patch = $this->app->patchPipeline()->createFromExecution(
                $missionId,
                $runId,
                $diff,
                isset($body['sessionId']) && is_string($body['sessionId']) ? $body['sessionId'] : null,
                isset($body['workspaceId']) && is_string($body['workspaceId']) ? $body['workspaceId'] : null,
            );
            JsonResponse::send($patch->toArray(), 201);

            return;
        }

        if ($method === 'POST' && preg_match('#^/patches/([A-Za-z0-9_-]+)/checks/run$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            JsonResponse::send($this->app->patchPipeline()->runPipeline($m[1])->toArray());

            return;
        }

        if ($method === 'POST' && preg_match('#^/patches/([A-Za-z0-9_-]+)/reviews/request$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            JsonResponse::send($this->app->patchPipeline()->runPipeline($m[1])->toArray());

            return;
        }

        if ($method === 'POST' && preg_match('#^/patches/([A-Za-z0-9_-]+)/approve$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::APPROVER);
            $body = $this->jsonBody();
            $summary = is_string($body['summary'] ?? null) ? (string) $body['summary'] : 'Approved by human';
            JsonResponse::send($this->app->patchPipeline()->humanApprove($m[1], $user->username(), $summary)->toArray());

            return;
        }

        if ($method === 'POST' && preg_match('#^/patches/([A-Za-z0-9_-]+)/reject$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::APPROVER);
            $body = $this->jsonBody();
            $summary = is_string($body['summary'] ?? null) ? (string) $body['summary'] : 'Rejected';
            $requestChanges = ($body['requestChanges'] ?? false) === true;
            JsonResponse::send($this->app->patchPipeline()->humanReject($m[1], $user->username(), $summary, $requestChanges)->toArray());

            return;
        }

        if ($method === 'POST' && preg_match('#^/patches/([A-Za-z0-9_-]+)/seal$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $sealed = $this->app->patchPipeline()->seal($m[1]);
            try {
                $this->app->knowledgeCapture()->capturePatchEvent($m[1], 'patch.sealed', $sealed->toArray());
            } catch (\Throwable) {
            }
            JsonResponse::send($sealed->toArray());

            return;
        }

        if ($method === 'GET' && preg_match('#^/missions/([A-Za-z0-9_-]+)/patches$#', $path, $m) === 1) {
            JsonResponse::send(['items' => $this->app->patches()->list($m[1])]);

            return;
        }

        if ($method === 'GET' && $path === '/settings/workspaces') {
            JsonResponse::send(['settings' => $this->app->workspaces()->settings()]);

            return;
        }

        if ($method === 'PUT' && $path === '/settings/workspaces') {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            JsonResponse::send(['settings' => $this->app->workspaces()->updateSettings($body)]);

            return;
        }

        if ($method === 'GET' && $path === '/workspaces') {
            $missionId = isset($_GET['missionId']) && is_string($_GET['missionId']) ? $_GET['missionId'] : null;
            $status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : null;
            JsonResponse::send(['items' => $this->app->workspaces()->list($missionId, $status)]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/workspaces/([A-Za-z0-9_-]+)$#', $path, $m) === 1) {
            $item = $this->app->workspaces()->get($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Engineering workspace not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && preg_match('#^/workspaces/([A-Za-z0-9_-]+)/timeline$#', $path, $m) === 1) {
            JsonResponse::send(['items' => $this->app->workspaces()->timeline($m[1])]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/workspaces/([A-Za-z0-9_-]+)/mounts$#', $path, $m) === 1) {
            $item = $this->app->workspaces()->mounts($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Engineering workspace not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && preg_match('#^/workspaces/([A-Za-z0-9_-]+)/size$#', $path, $m) === 1) {
            $item = $this->app->workspaces()->size($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Engineering workspace not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'POST' && preg_match('#^/workspaces/([A-Za-z0-9_-]+)/snapshot$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $snapshotId = $this->app->engineeringWorkspaces()->snapshot($m[1]);
            JsonResponse::send(['ok' => true, 'snapshotId' => $snapshotId]);

            return;
        }

        if ($method === 'POST' && preg_match('#^/workspaces/([A-Za-z0-9_-]+)/seal$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $sealed = $this->app->engineeringWorkspaces()->seal($m[1]);
            try {
                $this->app->knowledgeCapture()->captureWorkspaceSeal($m[1], $sealed->toArray());
            } catch (\Throwable) {
            }
            JsonResponse::send($sealed->toArray());

            return;
        }

        if ($method === 'POST' && preg_match('#^/workspaces/([A-Za-z0-9_-]+)/cleanup$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $this->app->engineeringWorkspaces()->cleanup($m[1]);
            JsonResponse::send(['ok' => true, 'workspaceId' => $m[1]]);

            return;
        }

        if ($method === 'POST' && $path === '/workspaces/cleanup') {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::ADMIN);
            $purged = $this->app->engineeringWorkspaces()->applyRetention();
            JsonResponse::send(['ok' => true, 'purged' => $purged]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/missions/([A-Za-z0-9_-]+)/workspace$#', $path, $m) === 1) {
            JsonResponse::send(['workspace' => $this->app->workspaces()->forMission($m[1])]);

            return;
        }

        if ($method === 'GET' && $path === '/settings/execution') {
            JsonResponse::send([
                'settings' => $this->app->execution()->settings(),
                'providers' => $this->app->execution()->listProviders(),
            ]);

            return;
        }

        if ($method === 'PUT' && $path === '/settings/execution') {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            JsonResponse::send([
                'settings' => $this->app->execution()->updateSettings($body),
            ]);

            return;
        }

        if ($method === 'GET' && $path === '/execution/providers') {
            JsonResponse::send(['items' => $this->app->execution()->listProviders()]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/execution/providers/([A-Za-z0-9_-]+)$#', $path, $m) === 1) {
            $item = $this->app->execution()->getProvider($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Provider not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && preg_match('#^/missions/([A-Za-z0-9_-]+)/execution$#', $path, $m) === 1) {
            $session = $this->app->execution()->sessionForMission($m[1]);
            JsonResponse::send(['session' => $session]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/execution/sessions/([A-Za-z0-9_-]+)$#', $path, $m) === 1) {
            $session = $this->app->execution()->session($m[1]);
            if ($session === null) {
                JsonResponse::problem('Not Found', 404, 'Execution session not found.');

                return;
            }
            JsonResponse::send($session);

            return;
        }

        if ($method === 'GET' && preg_match('#^/execution/sessions/([A-Za-z0-9_-]+)/events$#', $path, $m) === 1) {
            $after = isset($_GET['afterSeq']) ? (int) $_GET['afterSeq'] : 0;
            JsonResponse::send(['items' => $this->app->execution()->events($m[1], $after)]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/execution/sessions/([A-Za-z0-9_-]+)/prompt$#', $path, $m) === 1) {
            $item = $this->app->execution()->promptPreview($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Execution session not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'GET' && preg_match('#^/execution/sessions/([A-Za-z0-9_-]+)/metrics$#', $path, $m) === 1) {
            $item = $this->app->execution()->metrics($m[1]);
            if ($item === null) {
                JsonResponse::problem('Not Found', 404, 'Execution session not found.');

                return;
            }
            JsonResponse::send($item);

            return;
        }

        if ($method === 'POST' && preg_match('#^/execution/sessions/([A-Za-z0-9_-]+)/cancel$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $body = $this->jsonBody();
            $reason = is_string($body['reason'] ?? null) ? (string) $body['reason'] : 'Cancelled by operator.';
            $this->app->executionOrchestrator()->cancel($m[1], $reason);
            JsonResponse::send(['ok' => true, 'sessionId' => $m[1]]);

            return;
        }

        if ($method === 'POST' && preg_match('#^/execution/sessions/([A-Za-z0-9_-]+)/resume$#', $path, $m) === 1) {
            $this->requireCsrf();
            $this->app->auth()->assertRole($user, Role::OPERATOR);
            $result = $this->app->executionOrchestrator()->resume($m[1]);
            JsonResponse::send([
                'ok' => true,
                'status' => $result->status(),
                'message' => $result->message(),
                'context' => $result->context(),
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
