<?php

declare(strict_types=1);

namespace Tests\Infrastructure\EngineeringWorkspace;

use Aep\Application\EngineeringWorkspace\Model\EngineeringWorkspace;
use Aep\Infrastructure\EngineeringWorkspace\Git\GitWorktreeCheckout;
use Tests\Support\Assert;

final class GitWorktreeCheckoutTest
{
    public function test_valid_github_owner_repo_canonicalizes_and_builds_https_remote(): void
    {
        $checkout = new GitWorktreeCheckout(sys_get_temp_dir() . '/aep_git_cache_' . bin2hex(random_bytes(3)));
        Assert::same('anavasis/aep-codex-smoke', $checkout->canonicalizeGitHubRepository('anavasis/aep-codex-smoke'));

        $root = $this->tempDir();
        $remote = $this->createLocalRemote($root, 'develop');
        $commands = [];
        try {
            $runner = $this->rewritingRunner($remote, $commands);
            $checkout = new GitWorktreeCheckout($root . '/cache', $runner);
            $ws = $this->workspace($root . '/ws');
            $meta = $checkout->materialize($ws, [
                'projectId' => 'proj_1',
                'provider' => 'github',
                'repository' => 'anavasis/aep-codex-smoke',
            ]);
            Assert::same('https://github.com/anavasis/aep-codex-smoke.git', $meta['remoteUrl'] ?? null);
            Assert::same('develop', $meta['baseBranch'] ?? null);
            Assert::true(is_dir($ws->rootPath() . '/repo/.git') || is_file($ws->rootPath() . '/repo/.git'));
            Assert::true(($meta['headSha'] ?? '') !== '');
            foreach ($commands as $cmd) {
                $joined = implode(' ', $cmd);
                Assert::true(!preg_match('/\bpush\b/', $joined), 'must not push: ' . $joined);
                Assert::true(!preg_match('/(^| )commit( |$)/', $joined), 'must not commit: ' . $joined);
                Assert::true(!preg_match('/(^| )tag( |$)/', $joined), 'must not tag: ' . $joined);
                Assert::true(!str_contains($joined, ' pull request') && !preg_match('/\bpr\b/i', implode(' ', array_slice($cmd, 0, 3))), 'must not PR: ' . $joined);
            }
            $cloneUrl = null;
            foreach ($commands as $cmd) {
                if (($cmd[0] ?? '') === 'git' && in_array('clone', $cmd, true) && in_array('--mirror', $cmd, true)) {
                    foreach ($cmd as $part) {
                        if (is_string($part) && str_starts_with($part, 'https://github.com/')) {
                            $cloneUrl = $part;
                        }
                    }
                }
            }
            Assert::same('https://github.com/anavasis/aep-codex-smoke.git', $cloneUrl);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_malformed_repository_references_rejected(): void
    {
        $checkout = new GitWorktreeCheckout(sys_get_temp_dir() . '/aep_git_cache_' . bin2hex(random_bytes(3)));
        $bad = [
            'owner',
            'owner/repo/extra',
            '/owner/repo',
            'owner/repo/',
            'owner/../repo',
            'https://github.com/owner/repo.git',
            'git@github.com:owner/repo.git',
            'owner/repo.git',
            'owner/re po',
            'owner/repo?x=1',
            'owner/repo#frag',
            'evil.com/owner/repo',
            'owner/repo;rm',
            'owner/repo$(rm)',
            'owner/repo`id`',
        ];
        foreach ($bad as $repo) {
            Assert::throws(\InvalidArgumentException::class, static function () use ($checkout, $repo): void {
                $checkout->canonicalizeGitHubRepository($repo);
            }, 'expected reject for ' . $repo);
        }
    }

    public function test_embedded_credentials_and_hosts_rejected(): void
    {
        $checkout = new GitWorktreeCheckout(sys_get_temp_dir() . '/aep_git_cache_' . bin2hex(random_bytes(3)));
        foreach ([
            'https://user:pass@github.com/owner/repo.git',
            'user:token@github.com/owner/repo',
            'github.com/owner/repo',
        ] as $repo) {
            Assert::throws(\InvalidArgumentException::class, static function () use ($checkout, $repo): void {
                $checkout->canonicalizeGitHubRepository($repo);
            });
        }
    }

    public function test_explicit_missing_branch_fails_clearly(): void
    {
        $root = $this->tempDir();
        $remote = $this->createLocalRemote($root, 'main');
        try {
            $checkout = new GitWorktreeCheckout($root . '/cache', $this->rewritingRunner($remote));
            $ws = $this->workspace($root . '/ws');
            $e = Assert::throws(\RuntimeException::class, static function () use ($checkout, $ws): void {
                $checkout->materialize($ws, [
                    'projectId' => 'proj_x',
                    'provider' => 'github',
                    'repository' => 'owner/repo',
                    'baseBranch' => 'does-not-exist',
                ]);
            });
            Assert::true(str_contains($e->getMessage(), 'Explicit base branch does not exist'));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_empty_repository_fails_clearly(): void
    {
        $root = $this->tempDir();
        $remote = $root . '/empty.git';
        mkdir($remote, 0775, true);
        $this->realGit(['git', 'init', '--bare', $remote]);
        try {
            $checkout = new GitWorktreeCheckout($root . '/cache', $this->rewritingRunner($remote));
            $ws = $this->workspace($root . '/ws');
            Assert::throws(\RuntimeException::class, static function () use ($checkout, $ws): void {
                $checkout->materialize($ws, [
                    'projectId' => 'proj_empty',
                    'provider' => 'github',
                    'repository' => 'owner/empty',
                ]);
            });
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_missing_git_fails_clearly(): void
    {
        $root = $this->tempDir();
        try {
            $checkout = new GitWorktreeCheckout($root . '/cache', static function (array $cmd): string {
                throw new \RuntimeException('git binary missing');
            });
            $ws = $this->workspace($root . '/ws');
            $e = Assert::throws(\RuntimeException::class, static function () use ($checkout, $ws): void {
                $checkout->materialize($ws, [
                    'projectId' => 'proj_nogit',
                    'provider' => 'github',
                    'repository' => 'owner/repo',
                ]);
            });
            Assert::true(str_contains($e->getMessage(), 'git is required'));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_remote_default_branch_discovery_does_not_assume_main(): void
    {
        $root = $this->tempDir();
        $remote = $this->createLocalRemote($root, 'release-1');
        try {
            $checkout = new GitWorktreeCheckout($root . '/cache', $this->rewritingRunner($remote));
            $meta = $checkout->materialize($this->workspace($root . '/ws'), [
                'projectId' => 'proj_def',
                'provider' => 'github',
                'repository' => 'acme/widgets',
            ]);
            Assert::same('release-1', $meta['baseBranch'] ?? null);
            Assert::notSame('main', $meta['baseBranch'] ?? 'main');
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * @param list<list<string>>|null $commands
     * @return callable(list<string>): string
     */
    private function rewritingRunner(string $localRemote, ?array &$commands = null): callable
    {
        return function (array $cmd) use ($localRemote, &$commands): string {
            if ($commands !== null) {
                $commands[] = $cmd;
            }
            foreach ($cmd as $i => $part) {
                if (is_string($part) && str_starts_with($part, 'https://github.com/')) {
                    $cmd[$i] = $localRemote;
                }
            }

            return $this->realGit($cmd);
        };
    }

    /**
     * @param list<string> $cmd
     */
    private function realGit(array $cmd): string
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'GIT_TERMINAL_PROMPT' => '0',
            'HOME' => sys_get_temp_dir(),
            'LANG' => 'C',
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Unable to start git');
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

    private function createLocalRemote(string $root, string $defaultBranch): string
    {
        $work = $root . '/seed';
        $bare = $root . '/remote.git';
        mkdir($work, 0775, true);
        $this->realGit(['git', 'init', '-b', $defaultBranch, $work]);
        $this->realGit(['git', '-C', $work, 'config', 'user.email', 'aep@example.com']);
        $this->realGit(['git', '-C', $work, 'config', 'user.name', 'AEP Test']);
        file_put_contents($work . '/README.md', "# seed\n");
        $this->realGit(['git', '-C', $work, 'add', 'README.md']);
        $this->realGit(['git', '-C', $work, 'commit', '-m', 'initial']);
        $this->realGit(['git', 'clone', '--mirror', $work, $bare]);

        return $bare;
    }

    private function workspace(string $root): EngineeringWorkspace
    {
        if (!is_dir($root)) {
            mkdir($root, 0775, true);
        }

        return new EngineeringWorkspace(
            'ewsp_test',
            'msn_test',
            'run_test',
            EngineeringWorkspace::STATUS_PROVISIONING,
            '2026-08-03T12:00:00Z',
            '2026-08-03T12:00:00Z',
            $root,
        );
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/aep_gwt_' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);

        return $dir;
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
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
