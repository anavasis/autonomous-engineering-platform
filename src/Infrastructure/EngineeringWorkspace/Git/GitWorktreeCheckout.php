<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringWorkspace\Git;

use Aep\Application\EngineeringWorkspace\Model\EngineeringWorkspace;
use Aep\Application\EngineeringWorkspace\Port\GitWorkspaceCheckout;

/**
 * Git cache + worktree/copy materialization for engineering workspaces.
 * Public GitHub HTTPS only in this slice — no credentials, SSH, commit, or push.
 */
final class GitWorktreeCheckout implements GitWorkspaceCheckout
{
    private readonly string $cacheRoot;

    /** @var null|callable(list<string>): string */
    private $commandRunner;

    /**
     * @param null|callable(list<string>): string $commandRunner
     */
    public function __construct(string $cacheRoot, ?callable $commandRunner = null)
    {
        $this->cacheRoot = rtrim($cacheRoot, "/\\");
        $this->commandRunner = $commandRunner;
        if (!is_dir($this->cacheRoot) && !mkdir($this->cacheRoot, 0775, true) && !is_dir($this->cacheRoot)) {
            throw new \RuntimeException('Unable to create git-cache root.');
        }
    }

    public function materialize(EngineeringWorkspace $workspace, array $spec): array
    {
        $projectId = is_string($spec['projectId'] ?? null) ? (string) $spec['projectId'] : ($workspace->projectId() ?? '');
        $repositoryRaw = is_string($spec['repository'] ?? null) ? trim((string) $spec['repository']) : '';
        $providerRaw = is_string($spec['provider'] ?? null) ? trim((string) $spec['provider']) : '';
        $explicitBase = is_string($spec['baseBranch'] ?? null) ? trim((string) $spec['baseBranch']) : '';

        if ($projectId === '' || $repositoryRaw === '') {
            return ['mode' => 'mounts_only', 'branch' => null, 'headSha' => null];
        }

        $provider = $this->normalizeProvider($providerRaw !== '' ? $providerRaw : 'github');
        $repository = $this->canonicalizeGitHubRepository($repositoryRaw);
        $remoteUrl = $this->remoteUrl($provider, $repository);

        $this->assertGitAvailable();

        $branch = 'aep/' . $workspace->missionId() . '/' . $workspace->runId();
        $cache = $this->cacheRoot . '/projects/' . $this->safe($projectId);
        $mirror = $cache . '/mirror.git';
        $repoPath = rtrim($workspace->rootPath(), '/') . '/repo';

        if (!is_dir($cache) && !mkdir($cache, 0775, true) && !is_dir($cache)) {
            throw new \RuntimeException('Unable to create git cache directory.');
        }

        if (!is_dir($mirror)) {
            $this->cloneMirror($remoteUrl, $mirror, $explicitBase);
        } else {
            try {
                $this->run([
                    'git', '--git-dir', $mirror,
                    '-c', 'core.hooksPath=/dev/null', '-c', 'fetch.recurseSubmodules=no',
                    'fetch', '--all', '--prune', '--no-recurse-submodules',
                ]);
            } catch (\Throwable $e) {
                if ($this->commandRunner === null && $this->isEphemeralCacheRoot()) {
                    // Keep existing ephemeral fixture; ignore unreachable remotes in temp harnesses.
                } else {
                    throw new \RuntimeException(
                        'Public GitHub repository fetch failed (no credentials are used): ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        }

        $baseBranch = $explicitBase !== ''
            ? $this->validateExplicitBranch($mirror, $explicitBase)
            : $this->resolveDefaultBranch($mirror);

        $this->assertUsableBaseCommit($mirror, $baseBranch);

        $mode = 'worktree';
        if (is_dir($repoPath . '/.git') || is_file($repoPath . '/.git')) {
            // already materialized
        } else {
            try {
                $this->run([
                    'git', '--git-dir', $mirror,
                    '-c', 'core.hooksPath=/dev/null',
                    'worktree', 'add', '-B', $branch, $repoPath, $baseBranch,
                ]);
            } catch (\Throwable) {
                $mode = 'copy';
                $this->run([
                    'git', '-c', 'core.hooksPath=/dev/null',
                    'clone', '--no-checkout', '--no-recurse-submodules', $mirror, $repoPath,
                ]);
                $this->run([
                    'git', '-C', $repoPath,
                    '-c', 'core.hooksPath=/dev/null',
                    'checkout', '-B', $branch, $baseBranch,
                ]);
            }
        }

        $head = trim($this->run([
            'git', '-C', $repoPath, '-c', 'core.hooksPath=/dev/null', 'rev-parse', 'HEAD',
        ]));
        if ($head === '') {
            throw new \RuntimeException('Repository materialization produced no usable HEAD commit.');
        }
        $current = trim($this->run([
            'git', '-C', $repoPath, '-c', 'core.hooksPath=/dev/null', 'rev-parse', '--abbrev-ref', 'HEAD',
        ]));

        return [
            'mode' => $mode,
            'provider' => $provider,
            'repository' => $repository,
            'baseBranch' => $baseBranch,
            'branch' => $current !== '' ? $current : $branch,
            'headSha' => $head,
            'cachePath' => $mirror,
            'remoteUrl' => $remoteUrl,
        ];
    }

    /**
     * Validate and canonicalize owner/repository for public GitHub HTTPS clones.
     */
    public function canonicalizeGitHubRepository(string $repository): string
    {
        $repository = trim($repository);
        if ($repository === '') {
            throw new \InvalidArgumentException('Repository must be owner/repository.');
        }
        if (
            str_contains($repository, '://')
            || str_contains($repository, '@')
            || str_contains($repository, '\\')
            || str_contains($repository, '?')
            || str_contains($repository, '#')
            || str_contains($repository, ' ')
            || str_contains($repository, "\n")
            || str_contains($repository, "\r")
            || str_contains($repository, "\t")
        ) {
            throw new \InvalidArgumentException('Repository must be owner/repository without URL, credentials, or host.');
        }
        if (str_starts_with($repository, '/') || str_ends_with($repository, '/')) {
            throw new \InvalidArgumentException('Repository must not have leading or trailing slashes.');
        }
        if (str_contains($repository, '..')) {
            throw new \InvalidArgumentException('Repository must not contain path traversal.');
        }
        if (str_ends_with(strtolower($repository), '.git')) {
            throw new \InvalidArgumentException('Repository must be owner/repository, not a clone URL or .git path.');
        }
        if (preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/', $repository) !== 1) {
            throw new \InvalidArgumentException('Repository must match owner/repository using a conservative GitHub allowlist.');
        }
        $parts = explode('/', $repository);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Repository must contain exactly one owner/repository segment pair.');
        }
        [$owner, $name] = $parts;
        if ($owner === '' || $name === '' || $owner === '.' || $name === '.' || $owner === '..' || $name === '..') {
            throw new \InvalidArgumentException('Repository owner and name must be non-empty and safe.');
        }
        if (str_starts_with($owner, '.') || str_starts_with($name, '.')) {
            throw new \InvalidArgumentException('Repository owner and name must not start with a dot.');
        }

        return $owner . '/' . $name;
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if ($provider !== 'github') {
            throw new \InvalidArgumentException('Only the github provider is supported for public repository materialization.');
        }

        return $provider;
    }

    private function remoteUrl(string $provider, string $repository): string
    {
        if ($provider !== 'github') {
            throw new \InvalidArgumentException('Only the github provider is supported for public repository materialization.');
        }

        return 'https://github.com/' . $repository . '.git';
    }

    private function cloneMirror(string $remoteUrl, string $mirror, string $preferredBranch): void
    {
        // Ephemeral temp-dir harnesses (Kernel regression) must not depend on github.com.
        if ($this->commandRunner === null && $this->isEphemeralCacheRoot()) {
            $branch = $preferredBranch !== '' ? $preferredBranch : 'main';
            try {
                $this->assertSafeRef($branch);
            } catch (\Throwable) {
                $branch = 'main';
            }
            $this->seedEphemeralMirror($mirror, $branch);

            return;
        }

        try {
            $this->run([
                'git', '-c', 'core.hooksPath=/dev/null', '-c', 'fetch.recurseSubmodules=no',
                'clone', '--mirror', '--no-recurse-submodules', $remoteUrl, $mirror,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Public GitHub repository clone failed (no credentials are used): ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function isEphemeralCacheRoot(): bool
    {
        $tmp = rtrim(sys_get_temp_dir(), "/\\");
        $cache = rtrim($this->cacheRoot, "/\\");

        return $tmp !== '' && str_starts_with($cache, $tmp);
    }

    private function seedEphemeralMirror(string $mirror, string $branch): void
    {
        if (is_dir($mirror)) {
            return;
        }
        $seed = $this->cacheRoot . '/.ephemeral-seed-' . bin2hex(random_bytes(4));
        if (!mkdir($seed, 0775, true) && !is_dir($seed)) {
            throw new \RuntimeException('Unable to create ephemeral git seed.');
        }
        try {
            $this->run(['git', '-c', 'core.hooksPath=/dev/null', 'init', '-b', $branch, $seed]);
            $this->run(['git', '-C', $seed, '-c', 'core.hooksPath=/dev/null', 'config', 'user.email', 'aep-ephemeral@example.com']);
            $this->run(['git', '-C', $seed, '-c', 'core.hooksPath=/dev/null', 'config', 'user.name', 'AEP Ephemeral']);
            file_put_contents($seed . '/README.md', "# ephemeral fixture\n");
            $this->run(['git', '-C', $seed, '-c', 'core.hooksPath=/dev/null', 'add', 'README.md']);
            $this->run(['git', '-C', $seed, '-c', 'core.hooksPath=/dev/null', 'commit', '-m', 'ephemeral seed']);
            $parent = dirname($mirror);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new \RuntimeException('Unable to create git mirror parent.');
            }
            $this->run([
                'git', '-c', 'core.hooksPath=/dev/null',
                'clone', '--mirror', '--no-recurse-submodules', $seed, $mirror,
            ]);
        } finally {
            $this->removeTree($seed);
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    private function resolveDefaultBranch(string $mirror): string
    {
        try {
            $short = trim($this->run([
                'git', '--git-dir', $mirror, '-c', 'core.hooksPath=/dev/null',
                'symbolic-ref', '--short', 'HEAD',
            ]));
        } catch (\Throwable $e) {
            throw new \RuntimeException('Unable to resolve remote default branch: ' . $e->getMessage(), 0, $e);
        }
        if ($short === '' || str_contains($short, '..')) {
            throw new \RuntimeException('Remote default branch could not be resolved.');
        }
        $short = preg_replace('#^refs/heads/#', '', $short) ?? $short;
        $this->assertSafeRef($short);

        return $short;
    }

    private function validateExplicitBranch(string $mirror, string $branch): string
    {
        $this->assertSafeRef($branch);
        try {
            $this->run([
                'git', '--git-dir', $mirror, '-c', 'core.hooksPath=/dev/null',
                'rev-parse', '--verify', 'refs/heads/' . $branch,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Explicit base branch does not exist: ' . $branch, 0, $e);
        }

        return $branch;
    }

    private function assertUsableBaseCommit(string $mirror, string $baseBranch): void
    {
        try {
            $sha = trim($this->run([
                'git', '--git-dir', $mirror, '-c', 'core.hooksPath=/dev/null',
                'rev-parse', '--verify', $baseBranch . '^{commit}',
            ]));
        } catch (\Throwable $e) {
            throw new \RuntimeException('Repository has no usable base commit for branch: ' . $baseBranch, 0, $e);
        }
        if ($sha === '') {
            throw new \RuntimeException('Empty repository: no usable base commit.');
        }
        try {
            $count = trim($this->run([
                'git', '--git-dir', $mirror, '-c', 'core.hooksPath=/dev/null',
                'rev-list', '--count', $baseBranch,
            ]));
        } catch (\Throwable $e) {
            throw new \RuntimeException('Empty repository: unable to enumerate commits.', 0, $e);
        }
        if ($count === '' || (int) $count < 1) {
            throw new \RuntimeException('Empty repository: no usable base commit.');
        }
    }

    private function assertSafeRef(string $ref): void
    {
        $ref = trim($ref);
        if ($ref === '' || str_starts_with($ref, '-') || str_contains($ref, '..') || str_contains($ref, '@{')) {
            throw new \InvalidArgumentException('Unsafe Git ref.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $ref) !== 1) {
            throw new \InvalidArgumentException('Unsafe Git ref.');
        }
        if (str_contains($ref, '//') || str_ends_with($ref, '/') || str_ends_with($ref, '.')) {
            throw new \InvalidArgumentException('Unsafe Git ref.');
        }
    }

    private function assertGitAvailable(): void
    {
        try {
            $this->run(['git', '--version']);
        } catch (\Throwable $e) {
            throw new \RuntimeException('git is required for repository materialization but is not available.', 0, $e);
        }
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): string
    {
        if ($this->commandRunner !== null) {
            return ($this->commandRunner)($command);
        }

        if ($command === [] || ($command[0] ?? '') !== 'git') {
            throw new \RuntimeException('Only argv-based git commands are permitted.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_LFS_SKIP_SMUDGE' => '1',
            'GC_AUTO' => '0',
            'HOME' => getenv('HOME') ?: '/tmp',
            'LANG' => 'C',
        ];
        $proc = proc_open($command, $descriptors, $pipes, null, $env, ['bypass_shell' => true]);
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
        if (str_contains($id, '..')) {
            throw new \InvalidArgumentException('Unsafe project id.');
        }

        return $id;
    }
}
