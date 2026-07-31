<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringWorkspace\Git;

use Aep\Application\EngineeringWorkspace\Model\EngineeringWorkspace;
use Aep\Application\EngineeringWorkspace\Port\GitWorkspaceCheckout;

/**
 * Git cache + worktree/copy materialization for engineering workspaces.
 * Does not alter Git MVP semantics; uses git CLI for cache/worktree only.
 */
final class GitWorktreeCheckout implements GitWorkspaceCheckout
{
    private readonly string $cacheRoot;

    public function __construct(string $cacheRoot)
    {
        $this->cacheRoot = rtrim($cacheRoot, "/\\");
        if (!is_dir($this->cacheRoot) && !mkdir($this->cacheRoot, 0775, true) && !is_dir($this->cacheRoot)) {
            throw new \RuntimeException('Unable to create git-cache root.');
        }
    }

    public function materialize(EngineeringWorkspace $workspace, array $spec): array
    {
        $projectId = is_string($spec['projectId'] ?? null) ? (string) $spec['projectId'] : ($workspace->projectId() ?? '');
        $repository = is_string($spec['repository'] ?? null) ? trim((string) $spec['repository']) : '';
        $provider = is_string($spec['provider'] ?? null) ? trim((string) $spec['provider']) : 'git';
        $baseBranch = is_string($spec['baseBranch'] ?? null) && $spec['baseBranch'] !== ''
            ? (string) $spec['baseBranch']
            : 'main';
        if ($projectId === '' || $repository === '') {
            return ['mode' => 'mounts_only', 'branch' => null, 'headSha' => null];
        }
        if (str_contains($repository, '://') && str_contains($repository, '@')) {
            throw new \InvalidArgumentException('Repository URL must not embed credentials.');
        }

        $branch = 'aep/' . $workspace->missionId() . '/' . $workspace->runId();
        $cache = $this->cacheRoot . '/projects/' . $this->safe($projectId);
        $mirror = $cache . '/mirror.git';
        $repoPath = rtrim($workspace->rootPath(), '/') . '/repo';

        if (!is_dir($mirror)) {
            $this->run(['git', 'clone', '--mirror', $this->remoteUrl($provider, $repository), $mirror]);
        } else {
            $this->run(['git', '--git-dir', $mirror, 'fetch', '--all', '--prune']);
        }

        $mode = 'worktree';
        if (is_dir($repoPath . '/.git') || is_file($repoPath . '/.git')) {
            // already materialized
        } else {
            try {
                $this->run(['git', '--git-dir', $mirror, 'worktree', 'add', '-B', $branch, $repoPath, $baseBranch]);
            } catch (\Throwable) {
                $mode = 'copy';
                $this->run(['git', 'clone', '--no-checkout', $mirror, $repoPath]);
                $this->run(['git', '-C', $repoPath, 'checkout', '-B', $branch, $baseBranch]);
            }
        }

        $head = trim($this->run(['git', '-C', $repoPath, 'rev-parse', 'HEAD']));
        $current = trim($this->run(['git', '-C', $repoPath, 'rev-parse', '--abbrev-ref', 'HEAD']));

        return [
            'mode' => $mode,
            'provider' => $provider,
            'repository' => $repository,
            'baseBranch' => $baseBranch,
            'branch' => $current !== '' ? $current : $branch,
            'headSha' => $head,
            'cachePath' => $mirror,
        ];
    }

    private function remoteUrl(string $provider, string $repository): string
    {
        if (str_contains($repository, '://') || str_starts_with($repository, 'git@')) {
            return $repository;
        }
        $provider = strtolower($provider);
        if ($provider === 'github') {
            return 'https://github.com/' . ltrim($repository, '/') . '.git';
        }

        return $repository;
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): string
    {
        $cmd = '';
        foreach ($command as $part) {
            $cmd .= ($cmd === '' ? '' : ' ') . escapeshellarg($part);
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Unable to start git process.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new \RuntimeException('git failed: ' . trim($stderr !== '' ? $stderr : $stdout));
        }

        return $stdout;
    }

    private function safe(string $id): string
    {
        if ($id === '' || preg_match('/[^A-Za-z0-9_.-]/', $id) === 1) {
            throw new \InvalidArgumentException('Unsafe project id.');
        }

        return $id;
    }
}
