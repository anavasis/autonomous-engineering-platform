<?php

declare(strict_types=1);

namespace Tests\Infrastructure;

use Aep\Application\Git\GitCheckoutRequest;
use Aep\Application\Git\GitCloneRequest;
use Aep\Application\Git\GitCreateBranchRequest;
use Aep\Application\Git\GitRepoRequest;
use Aep\Infrastructure\Git\CliGitAdapter;
use Aep\Infrastructure\Git\GitProcessRunner;
use Tests\Support\Assert;

/**
 * Infrastructure CliGitAdapter tests against a local bare Git remote.
 */
final class GitCliAdapterTest
{
    public function test_workspace_creation_layout(): void
    {
        $root = $this->tempDir();
        try {
            $adapter = new CliGitAdapter($root);
            $remote = $this->createBareRemote($root);

            $result = $adapter->clone(new GitCloneRequest(
                'proj_ws',
                '2026-07-30T12:00:00Z',
                'local',
                $remote,
            ));
            Assert::true($result->isSucceeded(), $result->message());

            $projectDir = $root . '/projects/proj_ws';
            Assert::true(is_dir($projectDir . '/repository'));
            Assert::true(is_dir($projectDir . '/logs'));
            Assert::true(is_dir($projectDir . '/temp'));
            Assert::true(is_file($projectDir . '/metadata.json'));
            Assert::true(is_dir($projectDir . '/repository/.git'));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_clone_repository(): void
    {
        $root = $this->tempDir();
        try {
            $adapter = new CliGitAdapter($root);
            $remote = $this->createBareRemote($root);

            $result = $adapter->clone(new GitCloneRequest(
                'proj_clone',
                '2026-07-30T12:00:00Z',
                'local',
                $remote,
            ));

            Assert::true($result->isSucceeded(), $result->message());
            Assert::same('main', $result->context()['branch'] ?? null);

            $branch = $adapter->currentBranch(new GitRepoRequest('proj_clone', '2026-07-30T12:00:01Z'));
            Assert::true($branch->isSucceeded());
            Assert::same('main', $branch->context()['branch'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_clone_rejects_when_already_exists(): void
    {
        $root = $this->tempDir();
        try {
            $adapter = new CliGitAdapter($root);
            $remote = $this->createBareRemote($root);
            $req = new GitCloneRequest('proj_dup', '2026-07-30T12:00:00Z', 'local', $remote);
            Assert::true($adapter->clone($req)->isSucceeded());
            $second = $adapter->clone($req);
            Assert::true($second->isRejected());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_fetch_repository(): void
    {
        $root = $this->tempDir();
        try {
            $adapter = new CliGitAdapter($root);
            $remote = $this->createBareRemote($root);
            Assert::true($adapter->clone(new GitCloneRequest(
                'proj_fetch',
                '2026-07-30T12:00:00Z',
                'local',
                $remote,
            ))->isSucceeded());

            $fetchAt = '2026-07-30T13:00:00Z';
            $result = $adapter->fetch(new GitRepoRequest('proj_fetch', $fetchAt));
            Assert::true($result->isSucceeded(), $result->message());
            Assert::same($fetchAt, $result->context()['lastFetchAt'] ?? null);

            $meta = $adapter->metadata(new GitRepoRequest('proj_fetch', $fetchAt));
            Assert::same($fetchAt, $meta->lastFetchAt());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_status_clean_and_dirty(): void
    {
        $root = $this->tempDir();
        try {
            $adapter = new CliGitAdapter($root);
            $remote = $this->createBareRemote($root);
            Assert::true($adapter->clone(new GitCloneRequest(
                'proj_status',
                '2026-07-30T12:00:00Z',
                'local',
                $remote,
            ))->isSucceeded());

            $clean = $adapter->status(new GitRepoRequest('proj_status', '2026-07-30T12:00:01Z'));
            Assert::true($clean->isSucceeded());
            Assert::same(true, $clean->context()['clean'] ?? null);

            $repoDir = $root . '/projects/proj_status/repository';
            file_put_contents($repoDir . '/dirty.txt', "dirty\n");

            $dirty = $adapter->status(new GitRepoRequest('proj_status', '2026-07-30T12:00:02Z'));
            Assert::true($dirty->isSucceeded());
            Assert::same(false, $dirty->context()['clean'] ?? null);

            $health = $adapter->health(new GitRepoRequest('proj_status', '2026-07-30T12:00:03Z'));
            Assert::true($health->connected());
            Assert::true($health->remoteReachable());
            Assert::true(!$health->workingTreeClean());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_checkout_branch(): void
    {
        $root = $this->tempDir();
        try {
            $adapter = new CliGitAdapter($root);
            $remote = $this->createBareRemote($root, withDevelop: true);
            Assert::true($adapter->clone(new GitCloneRequest(
                'proj_co',
                '2026-07-30T12:00:00Z',
                'local',
                $remote,
            ))->isSucceeded());

            // Ensure remote branch is available locally
            Assert::true($adapter->fetch(new GitRepoRequest('proj_co', '2026-07-30T12:00:01Z'))->isSucceeded());

            $runner = new GitProcessRunner();
            $runner->run(['checkout', '-b', 'develop', 'origin/develop'], $root . '/projects/proj_co/repository');

            $result = $adapter->checkout(new GitCheckoutRequest('proj_co', '2026-07-30T12:00:02Z', 'main'));
            Assert::true($result->isSucceeded(), $result->message());
            Assert::same('main', $result->context()['branch'] ?? null);

            $result = $adapter->checkout(new GitCheckoutRequest('proj_co', '2026-07-30T12:00:03Z', 'develop'));
            Assert::true($result->isSucceeded(), $result->message());
            Assert::same('develop', $result->context()['branch'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_create_branch(): void
    {
        $root = $this->tempDir();
        try {
            $adapter = new CliGitAdapter($root);
            $remote = $this->createBareRemote($root);
            Assert::true($adapter->clone(new GitCloneRequest(
                'proj_br',
                '2026-07-30T12:00:00Z',
                'local',
                $remote,
            ))->isSucceeded());

            $result = $adapter->createBranch(new GitCreateBranchRequest(
                'proj_br',
                '2026-07-30T12:00:01Z',
                'feature/r8',
                true,
            ));
            Assert::true($result->isSucceeded(), $result->message());
            Assert::same('feature/r8', $result->context()['currentBranch'] ?? null);

            $branch = $adapter->currentBranch(new GitRepoRequest('proj_br', '2026-07-30T12:00:02Z'));
            Assert::same('feature/r8', $branch->context()['branch'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_metadata_cache_updates(): void
    {
        $root = $this->tempDir();
        try {
            $adapter = new CliGitAdapter($root);
            $remote = $this->createBareRemote($root);
            Assert::true($adapter->clone(new GitCloneRequest(
                'proj_meta',
                '2026-07-30T12:00:00Z',
                'local',
                $remote,
            ))->isSucceeded());

            $meta = $adapter->metadata(new GitRepoRequest('proj_meta', '2026-07-30T12:00:00Z'));
            Assert::same('main', $meta->currentBranch());
            Assert::true($meta->currentCommitSha() !== '');
            Assert::true(!str_contains($meta->remoteUrl(), '@') || str_starts_with($meta->remoteUrl(), 'file:'));
            Assert::true(in_array($meta->repositoryHealth(), ['healthy', 'degraded'], true));

            $raw = file_get_contents($root . '/projects/proj_meta/metadata.json');
            Assert::true(is_string($raw));
            Assert::true(!str_contains($raw, 'x-access-token'));
            Assert::true(!str_contains($raw, 'super-secret'));

            Assert::true($adapter->createBranch(new GitCreateBranchRequest(
                'proj_meta',
                '2026-07-30T12:00:01Z',
                'meta-branch',
            ))->isSucceeded());

            $meta2 = $adapter->metadata(new GitRepoRequest('proj_meta', '2026-07-30T12:00:01Z'));
            Assert::same('meta-branch', $meta2->currentBranch());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_operation_log_records_mutations(): void
    {
        $root = $this->tempDir();
        try {
            $adapter = new CliGitAdapter($root);
            $remote = $this->createBareRemote($root);
            Assert::true($adapter->clone(new GitCloneRequest(
                'proj_log',
                '2026-07-30T12:00:00Z',
                'local',
                $remote,
            ))->isSucceeded());
            Assert::true($adapter->fetch(new GitRepoRequest('proj_log', '2026-07-30T12:00:01Z'))->isSucceeded());
            Assert::true($adapter->createBranch(new GitCreateBranchRequest(
                'proj_log',
                '2026-07-30T12:00:02Z',
                'logged',
            ))->isSucceeded());
            Assert::true($adapter->checkout(new GitCheckoutRequest(
                'proj_log',
                '2026-07-30T12:00:03Z',
                'main',
            ))->isSucceeded());

            $log = $adapter->operationLog(new GitRepoRequest('proj_log', '2026-07-30T12:00:04Z'));
            $ops = array_map(static fn ($e) => $e->operation(), $log->entries());
            Assert::contains('Clone', $ops);
            Assert::contains('Fetch', $ops);
            Assert::contains('CreateBranch', $ops);
            Assert::contains('Checkout', $ops);

            foreach ($log->entries() as $entry) {
                Assert::true(!str_contains($entry->message(), 'x-access-token'));
            }
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Create a bare remote with an initial commit on main.
     *
     * @return string Absolute path usable as clone URL (filesystem path)
     */
    private function createBareRemote(string $root, bool $withDevelop = false): string
    {
        $runner = new GitProcessRunner();
        $seed = $root . '/seed';
        $bare = $root . '/remote.git';
        mkdir($seed, 0775, true);

        $this->gitOk($runner, ['init', '-b', 'main'], $seed);
        $this->gitOk($runner, ['config', 'user.email', 'aep@example.test'], $seed);
        $this->gitOk($runner, ['config', 'user.name', 'AEP Test'], $seed);
        file_put_contents($seed . '/README.md', "# fixture\n");
        $this->gitOk($runner, ['add', 'README.md'], $seed);
        $this->gitOk($runner, ['commit', '-m', 'initial'], $seed);

        if ($withDevelop) {
            $this->gitOk($runner, ['checkout', '-b', 'develop'], $seed);
            file_put_contents($seed . '/develop.txt', "develop\n");
            $this->gitOk($runner, ['add', 'develop.txt'], $seed);
            $this->gitOk($runner, ['commit', '-m', 'develop'], $seed);
            $this->gitOk($runner, ['checkout', 'main'], $seed);
        }

        $this->gitOk($runner, ['clone', '--bare', $seed, $bare], $root);

        return $bare;
    }

    /**
     * @param list<string> $args
     */
    private function gitOk(GitProcessRunner $runner, array $args, ?string $cwd): void
    {
        $proc = $runner->run($args, $cwd);
        if ($proc['exitCode'] !== 0) {
            throw new \RuntimeException(
                'fixture git failed: ' . implode(' ', $args) . ' :: ' .
                trim($proc['stderr'] !== '' ? $proc['stderr'] : $proc['stdout'])
            );
        }
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/aep_git_' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create temp dir.');
        }

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        if (is_file($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeDir($dir . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($dir);
    }
}
