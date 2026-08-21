<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringWorkspace\Service;

use Aep\Application\Artifact\ArtifactService;
use Aep\Application\EngineeringExecution\Model\PromptBundle;
use Aep\Application\EngineeringWorkspace\Model\EngineeringWorkspace;
use Aep\Application\EngineeringWorkspace\Model\WorkspaceHealth;
use Aep\Application\EngineeringWorkspace\Model\WorkspaceQuota;
use Aep\Application\EngineeringWorkspace\Port\EngineeringWorkspaceStore;
use Aep\Application\EngineeringWorkspace\Port\GitWorkspaceCheckout;
use Aep\Application\EngineeringWorkspace\Port\WorkspaceSettingsStore;
use Aep\Application\MissionControl\Support\Utc;

/**
 * Additive Engineering Workspace lifecycle service.
 * Mission Engine and EngineeringExecutionProvider never depend on this directly.
 */
final class EngineeringWorkspaceService
{
    public function __construct(
        private readonly EngineeringWorkspaceStore $store,
        private readonly WorkspaceSettingsStore $settings,
        private readonly ?GitWorkspaceCheckout $git = null,
        private readonly ?ArtifactService $artifacts = null,
    ) {
    }

    /**
     * @param array<string, string> $contextFiles
     * @param list<string> $allowedPaths
     * @param list<array{artifactId?: string, kind?: string, workspaceId?: string}> $artifactMounts
     * @param array<string, mixed> $gitSpec
     */
    public function ensureForSession(
        string $sessionId,
        string $missionId,
        string $runId,
        PromptBundle $prompt,
        array $contextFiles,
        array $allowedPaths = ['src/'],
        ?string $projectId = null,
        array $artifactMounts = [],
        array $gitSpec = [],
        int $redactions = 0,
    ): EngineeringWorkspace {
        $existing = $this->store->findByMissionRun($missionId, $runId);
        if ($existing !== null && !in_array($existing->status(), [
            EngineeringWorkspace::STATUS_PURGED,
            EngineeringWorkspace::STATUS_FAILED,
            EngineeringWorkspace::STATUS_CORRUPT,
        ], true)) {
            $existing->attachSession($sessionId);
            $this->mountPromptAndContext($existing, $prompt, $contextFiles, $redactions);
            $existing->setStatus(EngineeringWorkspace::STATUS_IN_USE, Utc::now(), 'Reattached session');
            $this->refreshUsage($existing);
            $this->store->save($existing);
            $this->store->markIndex($existing);
            $this->event($existing->workspaceId(), 'session.attached', ['sessionId' => $sessionId]);

            return $existing;
        }

        $settings = $this->settings->get();
        $quota = WorkspaceQuota::fromArray([
            'maxBytes' => is_int($settings['maxBytes'] ?? null) ? $settings['maxBytes'] : 2147483648,
            'maxFiles' => is_int($settings['maxFiles'] ?? null) ? $settings['maxFiles'] : 100000,
            'maxDurationSeconds' => is_int($settings['maxDurationSeconds'] ?? null) ? $settings['maxDurationSeconds'] : 86400,
        ]);

        $now = Utc::now();
        $id = 'ewsp_' . bin2hex(random_bytes(8));
        $ws = new EngineeringWorkspace(
            $id,
            $missionId,
            $runId,
            EngineeringWorkspace::STATUS_PLANNED,
            $now,
            $now,
            '',
            $projectId,
            [$sessionId],
            $allowedPaths !== [] ? $allowedPaths : ['src/'],
            quota: $quota,
            health: new WorkspaceHealth('degraded', 'Provisioning', $now),
            compatibility: [
                'aepMinVersion' => '0.4.0',
                'executionContract' => 'EngineeringExecutionProvider@0.3',
                'artifactPlane' => 'ArtifactWorkspace@0.1',
                'gitPlane' => 'GitMVP@0.1',
                'schemaVersion' => EngineeringWorkspace::SCHEMA_VERSION,
            ],
        );

        $this->store->save($ws);
        $this->event($id, 'workspace.created', ['missionId' => $missionId, 'runId' => $runId]);

        try {
            $ws->setStatus(EngineeringWorkspace::STATUS_PROVISIONING, Utc::now(), 'Provisioning tree');
            $root = $this->store->ensureTree($id);
            $ws->setRootPath($root);
            $this->store->save($ws);

            $ws->setEnvNames([
                'AEP_WORKSPACE_ID',
                'AEP_MISSION_ID',
                'AEP_RUN_ID',
                'AEP_ALLOWED_PATHS',
            ]);

            $repository = is_string($gitSpec['repository'] ?? null) ? trim((string) $gitSpec['repository']) : '';
            if ($this->git !== null && $repository !== '') {
                $cacheProjectId = $this->resolveGitCacheProjectId($projectId, $gitSpec);
                $gitMeta = $this->git->materialize($ws, array_merge($gitSpec, [
                    'projectId' => $cacheProjectId,
                ]));
                $ws->setGit($gitMeta);
                $this->event($id, 'git.materialized', [
                    'branch' => $gitMeta['branch'] ?? null,
                    'mode' => $gitMeta['mode'] ?? null,
                    'cacheProjectId' => $cacheProjectId,
                ]);
            } else {
                $ws->setGit(['mode' => 'mounts_only', 'branch' => null, 'headSha' => null]);
                $this->event($id, 'git.skipped', ['reason' => 'No project repository bound']);
            }

            $this->mountPromptAndContext($ws, $prompt, $contextFiles, $redactions);
            $this->mountArtifacts($ws, $artifactMounts);

            $fingerprint = $this->fingerprint($ws, $prompt);
            $ws->setReproFingerprint($fingerprint);
            $this->writeMetadata($ws);

            $baselineId = 'snap_baseline_' . bin2hex(random_bytes(4));
            $this->store->saveSnapshot($id, $baselineId, [
                'phase' => 'baseline',
                'at' => Utc::now(),
                'fingerprint' => $fingerprint,
            ]);
            $ws->setActiveSnapshotId($baselineId);

            $this->refreshUsage($ws);
            if ($ws->quota()->exceeded()) {
                $ws->setStatus(EngineeringWorkspace::STATUS_QUOTA_EXCEEDED, Utc::now(), 'Quota exceeded during provision');
                $this->store->save($ws);
                $this->store->markIndex($ws);
                throw new \RuntimeException('Workspace quota exceeded.');
            }

            $health = $this->healthCheck($ws);
            $ws->setHealth($health);
            $ws->setStatus(EngineeringWorkspace::STATUS_IN_USE, Utc::now(), 'Workspace ready');
            $this->store->save($ws);
            $this->store->markIndex($ws);
            $this->event($id, 'workspace.ready', ['fingerprint' => $fingerprint]);

            if (!$this->store->acquireLock($id, $sessionId)) {
                throw new \RuntimeException('Unable to acquire workspace lock.');
            }

            return $ws;
        } catch (\Throwable $e) {
            $ws->setStatus(EngineeringWorkspace::STATUS_FAILED, Utc::now(), $e->getMessage());
            $ws->setHealth(new WorkspaceHealth('unavailable', $e->getMessage(), Utc::now()));
            $this->store->save($ws);
            $this->store->markIndex($ws);
            $this->event($id, 'workspace.failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function snapshot(string $workspaceId, string $phase = 'checkpoint'): string
    {
        $ws = $this->require($workspaceId);
        $ws->setStatus(EngineeringWorkspace::STATUS_SNAPSHOTTING, Utc::now(), 'Snapshotting');
        $this->store->save($ws);
        $snapshotId = 'snap_' . bin2hex(random_bytes(6));
        $this->store->saveSnapshot($workspaceId, $snapshotId, [
            'phase' => $phase,
            'at' => Utc::now(),
            'status' => $ws->status(),
            'fingerprint' => $ws->reproFingerprint(),
        ]);
        $ws->setActiveSnapshotId($snapshotId);
        $ws->setStatus(EngineeringWorkspace::STATUS_IN_USE, Utc::now(), 'Snapshot complete');
        $this->store->save($ws);
        $this->event($workspaceId, 'workspace.snapshot', ['snapshotId' => $snapshotId, 'phase' => $phase]);

        return $snapshotId;
    }

    public function seal(string $workspaceId): EngineeringWorkspace
    {
        $ws = $this->require($workspaceId);
        $snap = $this->snapshot($workspaceId, 'seal');
        $ws = $this->require($workspaceId);
        $ws->setStatus(EngineeringWorkspace::STATUS_SEALED, Utc::now(), 'Sealed');
        $ws->setActiveSnapshotId($snap);
        $this->refreshUsage($ws);
        $this->writeMetadata($ws);
        $this->store->save($ws);
        $this->store->markIndex($ws);
        $this->store->releaseLock($workspaceId, $ws->sessionIds()[array_key_last($ws->sessionIds())] ?? 'seal');
        $this->event($workspaceId, 'workspace.sealed', ['snapshotId' => $snap]);

        return $ws;
    }

    public function resume(string $workspaceId, ?string $sessionId = null): EngineeringWorkspace
    {
        $ws = $this->require($workspaceId);
        $health = $this->healthCheck($ws);
        $ws->setHealth($health);
        if (!$health->isAvailable()) {
            if ($ws->activeSnapshotId() !== null) {
                $this->store->restoreSnapshot($workspaceId, $ws->activeSnapshotId());
                $health = $this->healthCheck($ws);
                $ws->setHealth($health);
            }
        }
        if (!$health->isAvailable()) {
            $ws->setStatus(EngineeringWorkspace::STATUS_CORRUPT, Utc::now(), $health->message());
            $this->store->save($ws);
            throw new \RuntimeException('Workspace cannot be resumed: ' . $health->message());
        }
        if ($sessionId !== null) {
            $ws->attachSession($sessionId);
            $this->store->acquireLock($workspaceId, $sessionId);
        }
        $ws->setStatus(EngineeringWorkspace::STATUS_IN_USE, Utc::now(), 'Resumed');
        $this->store->save($ws);
        $this->store->markIndex($ws);
        $this->event($workspaceId, 'workspace.resume', ['sessionId' => $sessionId]);

        return $ws;
    }

    public function healthCheck(EngineeringWorkspace $ws): WorkspaceHealth
    {
        $checks = [];
        $root = $ws->rootPath();
        $checks['rootExists'] = $root !== '' && is_dir($root);
        $meta = $this->store->readFile($ws->workspaceId(), '.aep/workspace.json');
        $checks['metadata'] = $meta !== null && $meta !== '';
        $checks['promptMount'] = $this->store->readFile($ws->workspaceId(), 'mounts/prompt/prompt.hash') !== null
            || $this->store->readFile($ws->workspaceId(), 'prompt.hash') !== null;
        $gitMode = $ws->git()['mode'] ?? 'mounts_only';
        if ($gitMode !== 'mounts_only') {
            $checks['repoExists'] = is_dir(rtrim($root, '/') . '/repo');
        } else {
            $checks['repoExists'] = true;
        }
        $this->refreshUsage($ws);
        $checks['quotaOk'] = !$ws->quota()->exceeded();

        $failed = [];
        foreach ($checks as $name => $ok) {
            if ($ok !== true) {
                $failed[] = $name;
            }
        }
        $status = 'ok';
        $message = 'Healthy';
        if ($failed !== []) {
            $status = in_array('rootExists', $failed, true) || in_array('metadata', $failed, true)
                ? 'unavailable'
                : 'degraded';
            $message = 'Failed checks: ' . implode(', ', $failed);
        }

        return new WorkspaceHealth($status, $message, Utc::now(), $checks);
    }

    public function cleanup(string $workspaceId): void
    {
        $ws = $this->require($workspaceId);
        if (!in_array($ws->status(), [
            EngineeringWorkspace::STATUS_SEALED,
            EngineeringWorkspace::STATUS_FAILED,
            EngineeringWorkspace::STATUS_ARCHIVED,
            EngineeringWorkspace::STATUS_QUOTA_EXCEEDED,
        ], true)) {
            $this->seal($workspaceId);
            $ws = $this->require($workspaceId);
        }
        $ws->setStatus(EngineeringWorkspace::STATUS_ARCHIVED, Utc::now(), 'Archived for cleanup');
        $this->store->save($ws);
        $this->store->deleteTree($workspaceId);
        $ws->setStatus(EngineeringWorkspace::STATUS_PURGED, Utc::now(), 'Purged');
        $ws->setRootPath('');
        $this->store->save($ws);
        $this->store->markIndex($ws);
        $this->event($workspaceId, 'workspace.purged', []);
    }

    /**
     * @return list<string> purged workspace ids
     */
    public function applyRetention(?string $nowUtc = null): array
    {
        $now = $nowUtc ?? Utc::now();
        $settings = $this->settings->get();
        $retainFailedDays = is_int($settings['retainFailedDays'] ?? null) ? $settings['retainFailedDays'] : 14;
        $retainSealedDays = is_int($settings['retainSealedDays'] ?? null) ? $settings['retainSealedDays'] : 30;
        $keepLatestN = is_int($settings['keepLatestN'] ?? null) ? $settings['keepLatestN'] : 20;
        $purged = [];

        $byMission = [];
        foreach ($this->store->list() as $ws) {
            if ($ws->status() === EngineeringWorkspace::STATUS_PURGED) {
                continue;
            }
            $byMission[$ws->missionId()][] = $ws;
            $ageDays = $this->ageDays($ws->updatedAtUtc(), $now);
            $should = match ($ws->status()) {
                EngineeringWorkspace::STATUS_FAILED, EngineeringWorkspace::STATUS_QUOTA_EXCEEDED => $ageDays >= $retainFailedDays,
                EngineeringWorkspace::STATUS_SEALED, EngineeringWorkspace::STATUS_ARCHIVED => $ageDays >= $retainSealedDays,
                default => false,
            };
            if ($should) {
                $this->cleanup($ws->workspaceId());
                $purged[] = $ws->workspaceId();
            }
        }

        foreach ($byMission as $list) {
            usort($list, static fn ($a, $b) => strcmp($b->updatedAtUtc(), $a->updatedAtUtc()));
            foreach (array_slice($list, $keepLatestN) as $old) {
                if ($old->status() === EngineeringWorkspace::STATUS_PURGED) {
                    continue;
                }
                if (in_array($old->workspaceId(), $purged, true)) {
                    continue;
                }
                if (in_array($old->status(), [
                    EngineeringWorkspace::STATUS_SEALED,
                    EngineeringWorkspace::STATUS_ARCHIVED,
                    EngineeringWorkspace::STATUS_FAILED,
                ], true)) {
                    $this->cleanup($old->workspaceId());
                    $purged[] = $old->workspaceId();
                }
            }
        }

        return $purged;
    }

    public function get(string $workspaceId): ?EngineeringWorkspace
    {
        return $this->store->find($workspaceId);
    }

    public function forMission(string $missionId): ?EngineeringWorkspace
    {
        $list = $this->store->list($missionId);
        if ($list === []) {
            return null;
        }
        usort($list, static fn ($a, $b) => strcmp($b->updatedAtUtc(), $a->updatedAtUtc()));

        return $list[0];
    }

    /**
     * @return list<EngineeringWorkspace>
     */
    public function list(?string $missionId = null, ?string $status = null): array
    {
        return $this->store->list($missionId, $status);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function timeline(string $workspaceId): array
    {
        return $this->store->timeline($workspaceId);
    }

    /**
     * @param array<string, string> $contextFiles
     */
    private function mountPromptAndContext(
        EngineeringWorkspace $ws,
        PromptBundle $prompt,
        array $contextFiles,
        int $redactions,
    ): void {
        $id = $ws->workspaceId();
        $promptBody = "# System\n\n" . $prompt->system() . "\n\n# User\n\n" . $prompt->user() . "\n";
        $this->store->writeFile($id, 'mounts/prompt/PROMPT.md', $promptBody);
        $this->store->writeFile($id, 'mounts/prompt/prompt.hash', $prompt->hash() . "\n");
        // Provider-compatible aliases at workspace root
        $this->store->writeFile($id, 'PROMPT.md', $promptBody);
        $this->store->writeFile($id, 'prompt.hash', $prompt->hash() . "\n");

        $paths = [];
        foreach ($contextFiles as $path => $contents) {
            $safe = str_replace(['..', '\\'], ['_', '/'], $path);
            $this->store->writeFile($id, 'mounts/context/' . $safe, $contents);
            $this->store->writeFile($id, 'context/' . $safe, $contents);
            $this->store->writeFile($id, '.aep/baseline/' . $safe, $contents);
            $this->store->writeFile($id, '.baseline/' . $safe, $contents);
            $paths[] = $safe;
        }
        $ws->mounts()->setPromptHash($prompt->hash());
        $ws->mounts()->setContextFiles($paths);
        $ws->mounts()->setRedactions($redactions);
        $this->event($id, 'mounts.prompt_context', ['files' => count($paths), 'promptHash' => $prompt->hash()]);
    }

    /**
     * @param list<array{artifactId?: string, kind?: string, workspaceId?: string}> $artifactMounts
     */
    private function mountArtifacts(EngineeringWorkspace $ws, array $artifactMounts): void
    {
        if ($this->artifacts === null || $artifactMounts === []) {
            return;
        }
        $mounted = [];
        foreach ($artifactMounts as $mount) {
            $artifactId = is_string($mount['artifactId'] ?? null) ? $mount['artifactId'] : '';
            $artifactWs = is_string($mount['workspaceId'] ?? null) ? $mount['workspaceId'] : '';
            if ($artifactId === '' || $artifactWs === '') {
                continue;
            }
            try {
                $contents = $this->artifacts->readContents($artifactWs, $artifactId);
                $kind = is_string($mount['kind'] ?? null) ? $mount['kind'] : 'file';
                $checksum = 'sha256:' . hash('sha256', $contents);
                $this->store->writeFile(
                    $ws->workspaceId(),
                    'mounts/artifacts/' . $artifactId . '/content',
                    $contents
                );
                $this->store->writeFile(
                    $ws->workspaceId(),
                    'mounts/artifacts/' . $artifactId . '/meta.json',
                    json_encode(['artifactId' => $artifactId, 'kind' => $kind, 'checksum' => $checksum], JSON_THROW_ON_ERROR)
                );
                $mounted[] = ['artifactId' => $artifactId, 'kind' => $kind, 'checksum' => $checksum];
            } catch (\Throwable) {
                continue;
            }
        }
        $ws->mounts()->setArtifacts($mounted);
        $this->event($ws->workspaceId(), 'mounts.artifacts', ['count' => count($mounted)]);
    }

    private function fingerprint(EngineeringWorkspace $ws, PromptBundle $prompt): string
    {
        $payload = json_encode([
            'baseSha' => $ws->git()['headSha'] ?? null,
            'promptHash' => $prompt->hash(),
            'mounts' => $ws->mounts()->toArray(),
            'allowedPaths' => $ws->allowedPaths(),
            'schemaVersion' => EngineeringWorkspace::SCHEMA_VERSION,
        ], JSON_THROW_ON_ERROR);

        return 'sha256:' . hash('sha256', $payload);
    }

    private function writeMetadata(EngineeringWorkspace $ws): void
    {
        $this->store->writeFile(
            $ws->workspaceId(),
            '.aep/workspace.json',
            json_encode($ws->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );
        $this->store->writeFile(
            $ws->workspaceId(),
            '.aep/env.manifest.json',
            json_encode(['names' => $ws->toArray()['envNames'] ?? []], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );
        $this->store->writeFile(
            $ws->workspaceId(),
            '.aep/quota.json',
            json_encode($ws->quota()->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );
    }

    private function refreshUsage(EngineeringWorkspace $ws): void
    {
        $size = $this->store->measureSize($ws->workspaceId());
        $ws->quota()->setUsage($size['bytes'], $size['files']);
    }

    private function require(string $workspaceId): EngineeringWorkspace
    {
        $ws = $this->store->find($workspaceId);
        if ($ws === null) {
            throw new \InvalidArgumentException('Unknown engineering workspace: ' . $workspaceId);
        }

        return $ws;
    }

    /** @param array<string, mixed> $data */
    private function event(string $workspaceId, string $type, array $data): void
    {
        $this->store->appendTimeline($workspaceId, [
            'at' => Utc::now(),
            'type' => $type,
            'data' => $data,
        ]);
    }

    private function ageDays(string $fromUtc, string $nowUtc): int
    {
        try {
            $from = new \DateTimeImmutable($fromUtc);
            $now = new \DateTimeImmutable($nowUtc);
        } catch (\Exception) {
            return 0;
        }

        return (int) floor(($now->getTimestamp() - $from->getTimestamp()) / 86400);
    }

    /**
     * Resolve a filesystem-safe cache identity for git materialization.
     * Project-bound missions keep their projectId; unbound missions use a
     * deterministic non-secret hash so owner/repository never appear in paths.
     *
     * @param array<string, mixed> $gitSpec
     */
    private function resolveGitCacheProjectId(?string $projectId, array $gitSpec): string
    {
        if (is_string($projectId) && trim($projectId) !== '') {
            return trim($projectId);
        }

        $provider = is_string($gitSpec['provider'] ?? null) ? strtolower(trim((string) $gitSpec['provider'])) : 'github';
        $repository = is_string($gitSpec['repository'] ?? null) ? trim((string) $gitSpec['repository']) : '';
        if ($provider === '' || $repository === '') {
            throw new \InvalidArgumentException('git.provider and git.repository are required when projectId is absent.');
        }

        return 'repo_' . hash('sha256', $provider . ':' . $repository);
    }
}
