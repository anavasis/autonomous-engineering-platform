<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringWorkspace\Store;

use Aep\Application\EngineeringWorkspace\Model\EngineeringWorkspace;
use Aep\Application\EngineeringWorkspace\Port\EngineeringWorkspaceStore;

final class FilesystemEngineeringWorkspaceStore implements EngineeringWorkspaceStore
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, "/\\");
        foreach ([
            $this->root,
            $this->root . '/index',
            $this->root . '/index/by-mission',
            $this->root . '/index/by-session',
            $this->root . '/index/by-run',
        ] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create workspace store dir: ' . $dir);
            }
        }
    }

    public function save(EngineeringWorkspace $workspace): void
    {
        $dir = $this->workspaceDir($workspace->workspaceId());
        if (!is_dir($dir . '/.aep') && !mkdir($dir . '/.aep', 0775, true) && !is_dir($dir . '/.aep')) {
            throw new \RuntimeException('Unable to create workspace metadata dir.');
        }
        file_put_contents(
            $dir . '/.aep/workspace.json',
            json_encode($workspace->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );
        $this->markIndex($workspace);
    }

    public function find(string $workspaceId): ?EngineeringWorkspace
    {
        $path = $this->workspaceDir($workspaceId) . '/.aep/workspace.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? EngineeringWorkspace::fromArray($data) : null;
    }

    public function findByMissionRun(string $missionId, string $runId): ?EngineeringWorkspace
    {
        $path = $this->root . '/index/by-run/' . $this->safe($missionId) . '__' . $this->safe($runId) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        $id = is_array($data) && is_string($data['workspaceId'] ?? null) ? $data['workspaceId'] : null;

        return $id !== null ? $this->find($id) : null;
    }

    public function findBySession(string $sessionId): ?EngineeringWorkspace
    {
        $path = $this->root . '/index/by-session/' . $this->safe($sessionId) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        $id = is_array($data) && is_string($data['workspaceId'] ?? null) ? $data['workspaceId'] : null;

        return $id !== null ? $this->find($id) : null;
    }

    public function list(?string $missionId = null, ?string $status = null): array
    {
        $out = [];
        foreach (glob($this->root . '/ewsp_*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            $ws = $this->find($id);
            if ($ws === null) {
                continue;
            }
            if ($missionId !== null && $ws->missionId() !== $missionId) {
                continue;
            }
            if ($status !== null && $ws->status() !== $status) {
                continue;
            }
            $out[] = $ws;
        }

        return $out;
    }

    public function ensureTree(string $workspaceId): string
    {
        $dir = $this->workspaceDir($workspaceId);
        foreach ([
            $dir,
            $dir . '/.aep',
            $dir . '/.aep/baseline',
            $dir . '/.aep/snapshots',
            $dir . '/repo',
            $dir . '/mounts/artifacts',
            $dir . '/mounts/prompt',
            $dir . '/mounts/context',
            $dir . '/context',
            $dir . '/.baseline',
            $dir . '/scratch',
        ] as $path) {
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                throw new \RuntimeException('Unable to create workspace path: ' . $path);
            }
        }

        return $dir;
    }

    public function writeFile(string $workspaceId, string $relativePath, string $contents): void
    {
        $target = $this->resolve($workspaceId, $relativePath);
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new \RuntimeException('Unable to create parent for ' . $relativePath);
        }
        file_put_contents($target, $contents);
    }

    public function readFile(string $workspaceId, string $relativePath): ?string
    {
        $target = $this->resolve($workspaceId, $relativePath);
        if (!is_file($target)) {
            return null;
        }

        return (string) file_get_contents($target);
    }

    public function copyFile(string $workspaceId, string $absoluteSource, string $relativeDest): void
    {
        if (!is_file($absoluteSource)) {
            throw new \InvalidArgumentException('Source file missing.');
        }
        $this->writeFile($workspaceId, $relativeDest, (string) file_get_contents($absoluteSource));
    }

    public function measureSize(string $workspaceId): array
    {
        $dir = $this->workspaceDir($workspaceId);
        if (!is_dir($dir)) {
            return ['bytes' => 0, 'files' => 0];
        }
        $bytes = 0;
        $files = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $files++;
                $bytes += (int) $file->getSize();
            }
        }

        return ['bytes' => $bytes, 'files' => $files];
    }

    public function appendTimeline(string $workspaceId, array $event): void
    {
        $path = $this->workspaceDir($workspaceId) . '/.aep/timeline.jsonl';
        $parent = dirname($path);
        if (!is_dir($parent)) {
            mkdir($parent, 0775, true);
        }
        file_put_contents($path, json_encode($event, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    }

    public function timeline(string $workspaceId): array
    {
        $path = $this->workspaceDir($workspaceId) . '/.aep/timeline.jsonl';
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function saveSnapshot(string $workspaceId, string $snapshotId, array $meta): void
    {
        $dir = $this->workspaceDir($workspaceId) . '/.aep/snapshots/' . $this->safe($snapshotId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create snapshot dir.');
        }
        file_put_contents($dir . '/meta.json', json_encode($meta, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $context = $this->workspaceDir($workspaceId) . '/context';
        if (is_dir($context)) {
            $this->copyTree($context, $dir . '/context');
        }
        $prompt = $this->workspaceDir($workspaceId) . '/mounts/prompt';
        if (is_dir($prompt)) {
            $this->copyTree($prompt, $dir . '/prompt');
        }
    }

    public function restoreSnapshot(string $workspaceId, string $snapshotId): void
    {
        $dir = $this->workspaceDir($workspaceId) . '/.aep/snapshots/' . $this->safe($snapshotId);
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException('Snapshot not found: ' . $snapshotId);
        }
        if (is_dir($dir . '/context')) {
            $this->copyTree($dir . '/context', $this->workspaceDir($workspaceId) . '/context');
            $this->copyTree($dir . '/context', $this->workspaceDir($workspaceId) . '/mounts/context');
        }
        if (is_dir($dir . '/prompt')) {
            $this->copyTree($dir . '/prompt', $this->workspaceDir($workspaceId) . '/mounts/prompt');
        }
    }

    public function acquireLock(string $workspaceId, string $owner, int $ttlSeconds = 600): bool
    {
        $path = $this->workspaceDir($workspaceId) . '/.aep/lock';
        if (is_file($path)) {
            $raw = json_decode((string) file_get_contents($path), true);
            if (is_array($raw)) {
                $expires = is_int($raw['expiresAt'] ?? null) ? $raw['expiresAt'] : 0;
                $existingOwner = is_string($raw['owner'] ?? null) ? $raw['owner'] : '';
                if ($expires > time() && $existingOwner !== $owner) {
                    return false;
                }
            }
        }
        file_put_contents($path, json_encode([
            'owner' => $owner,
            'expiresAt' => time() + max(1, $ttlSeconds),
            'at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return true;
    }

    public function releaseLock(string $workspaceId, string $owner): void
    {
        $path = $this->workspaceDir($workspaceId) . '/.aep/lock';
        if (!is_file($path)) {
            return;
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (is_array($raw) && ($raw['owner'] ?? null) === $owner) {
            unlink($path);
        }
    }

    public function deleteTree(string $workspaceId): void
    {
        $dir = $this->workspaceDir($workspaceId);
        if (!is_dir($dir)) {
            return;
        }
        // Keep metadata tombstone; remove heavy trees
        foreach (['repo', 'mounts', 'context', 'scratch', '.baseline'] as $child) {
            $path = $dir . '/' . $child;
            if (is_dir($path)) {
                $this->rmTree($path);
            }
        }
    }

    public function markIndex(EngineeringWorkspace $workspace): void
    {
        $payload = json_encode([
            'workspaceId' => $workspace->workspaceId(),
            'missionId' => $workspace->missionId(),
            'runId' => $workspace->runId(),
            'status' => $workspace->status(),
            'updatedAtUtc' => $workspace->toArray()['updatedAtUtc'] ?? '',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        file_put_contents(
            $this->root . '/index/by-run/' . $this->safe($workspace->missionId()) . '__' . $this->safe($workspace->runId()) . '.json',
            $payload
        );
        file_put_contents(
            $this->root . '/index/by-mission/' . $this->safe($workspace->missionId()) . '.json',
            $payload
        );
        foreach ($workspace->sessionIds() as $sessionId) {
            file_put_contents(
                $this->root . '/index/by-session/' . $this->safe($sessionId) . '.json',
                $payload
            );
        }
    }

    private function workspaceDir(string $workspaceId): string
    {
        return $this->root . '/' . $this->safe($workspaceId);
    }

    private function resolve(string $workspaceId, string $relativePath): string
    {
        $rel = str_replace('\\', '/', $relativePath);
        if ($rel === '' || str_contains($rel, '..')) {
            throw new \InvalidArgumentException('Invalid relative path.');
        }

        return $this->workspaceDir($workspaceId) . '/' . ltrim($rel, '/');
    }

    private function safe(string $id): string
    {
        if ($id === '' || preg_match('/[^A-Za-z0-9_.-]/', $id) === 1) {
            throw new \InvalidArgumentException('Unsafe id: ' . $id);
        }

        return $id;
    }

    private function copyTree(string $from, string $to): void
    {
        if (!is_dir($to) && !mkdir($to, 0775, true) && !is_dir($to)) {
            throw new \RuntimeException('Unable to create copy destination.');
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $to . '/' . $it->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    mkdir($parent, 0775, true);
                }
                copy($item->getPathname(), $target);
            }
        }
    }

    private function rmTree(string $dir): void
    {
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
