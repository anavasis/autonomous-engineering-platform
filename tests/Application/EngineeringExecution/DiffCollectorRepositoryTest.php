<?php

declare(strict_types=1);

namespace Tests\Application\EngineeringExecution;

use Aep\Application\EngineeringExecution\Service\DiffCollector;
use Tests\Support\Assert;

final class DiffCollectorRepositoryTest
{
    public function test_root_result_diff_retains_priority(): void
    {
        $root = $this->tempDir();
        try {
            $repo = $root . '/repo';
            $this->initRepo($repo);
            file_put_contents($repo . '/tracked.txt', "changed\n");
            file_put_contents($root . '/RESULT.diff', "--- a/from-provider\n+++ b/from-provider\n@@\n-old\n+new\n");
            $out = (new DiffCollector())->collect($root);
            Assert::true(str_contains($out['text'], 'from-provider'));
            Assert::contains('from-provider', $out['files']);
            Assert::true(!str_contains($out['text'], 'tracked.txt'));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_tracked_modification_under_repo_is_captured(): void
    {
        $root = $this->tempDir();
        try {
            $repo = $root . '/repo';
            $this->initRepo($repo);
            file_put_contents($repo . '/tracked.txt', "modified line\n");
            $out = (new DiffCollector())->collect($root);
            Assert::true(str_contains($out['text'], 'tracked.txt'));
            Assert::true(str_contains($out['text'], 'modified line'));
            Assert::contains('tracked.txt', $out['files']);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_new_untracked_readme_is_captured_as_addition(): void
    {
        $root = $this->tempDir();
        try {
            $repo = $root . '/repo';
            $this->initRepo($repo);
            file_put_contents($repo . '/README.md', "# AEP Codex Smoke Test\n");
            $out = (new DiffCollector())->collect($root);
            Assert::true(str_contains($out['text'], 'README.md'));
            Assert::true(str_contains($out['text'], '# AEP Codex Smoke Test') || str_contains($out['text'], '+# AEP Codex Smoke Test'));
            Assert::contains('README.md', $out['files']);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_deletion_is_captured(): void
    {
        $root = $this->tempDir();
        try {
            $repo = $root . '/repo';
            $this->initRepo($repo);
            unlink($repo . '/tracked.txt');
            $out = (new DiffCollector())->collect($root);
            Assert::true(str_contains($out['text'], 'tracked.txt'));
            Assert::true(str_contains($out['text'], 'deleted file') || str_contains($out['text'], '-hello'));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_staged_and_unstaged_changes_are_both_visible(): void
    {
        $root = $this->tempDir();
        try {
            $repo = $root . '/repo';
            $this->initRepo($repo);
            file_put_contents($repo . '/tracked.txt', "staged-version\n");
            $this->git($repo, ['add', 'tracked.txt']);
            file_put_contents($repo . '/tracked.txt', "unstaged-version\n");
            file_put_contents($repo . '/other.txt', "also\n");
            $this->git($repo, ['add', 'other.txt']);
            $out = (new DiffCollector())->collect($root);
            Assert::true(str_contains($out['text'], 'unstaged-version'));
            Assert::true(str_contains($out['text'], 'other.txt'));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_binary_file_handling_does_not_emit_raw_binary(): void
    {
        $root = $this->tempDir();
        try {
            $repo = $root . '/repo';
            $this->initRepo($repo);
            $binary = "\x00\x01\x02\xffbinary-payload\x00";
            file_put_contents($repo . '/blob.bin', $binary);
            $out = (new DiffCollector())->collect($root);
            Assert::true(str_contains($out['text'], 'blob.bin'));
            Assert::true(!str_contains($out['text'], $binary));
            Assert::true(
                str_contains($out['text'], 'Binary files')
                || str_contains($out['text'], 'GIT binary patch')
                || !str_contains($out['text'], "\x00")
            );
            Assert::true(!str_contains($out['text'], "\x00"));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_non_git_context_baseline_behavior_unchanged(): void
    {
        $root = $this->tempDir();
        try {
            mkdir($root . '/context/src', 0775, true);
            mkdir($root . '/.baseline/src', 0775, true);
            file_put_contents($root . '/.baseline/src/a.txt', "before\n");
            file_put_contents($root . '/context/src/a.txt', "after\n");
            $out = (new DiffCollector())->collect($root, ['src/']);
            Assert::true(str_contains($out['text'], 'src/a.txt'));
            Assert::true(str_contains($out['text'], 'after'));
            Assert::contains('src/a.txt', $out['files']);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_collection_does_not_stage_commit_push_or_modify_repository(): void
    {
        $root = $this->tempDir();
        try {
            $repo = $root . '/repo';
            $this->initRepo($repo);
            file_put_contents($repo . '/README.md', "# new\n");
            $headBefore = trim($this->git($repo, ['rev-parse', 'HEAD']));
            $statusBefore = $this->git($repo, ['status', '--porcelain=v1']);
            $cachedBefore = $this->gitAllowDiff($repo, ['diff', '--cached']);
            (new DiffCollector())->collect($root);
            Assert::same($headBefore, trim($this->git($repo, ['rev-parse', 'HEAD'])));
            Assert::same($statusBefore, $this->git($repo, ['status', '--porcelain=v1']));
            Assert::same($cachedBefore, $this->gitAllowDiff($repo, ['diff', '--cached']));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_invalid_repo_fails_clearly_when_git_metadata_present(): void
    {
        $root = $this->tempDir();
        try {
            mkdir($root . '/repo/.git', 0775, true);
            // Not a real git repo.
            Assert::throws(\RuntimeException::class, static function () use ($root): void {
                (new DiffCollector())->collect($root);
            });
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_missing_git_fails_clearly_for_repo_collection(): void
    {
        $root = $this->tempDir();
        try {
            mkdir($root . '/repo/.git', 0775, true);
            $collector = new DiffCollector(static function (array $cmd): string {
                throw new \RuntimeException('git missing');
            });
            $e = Assert::throws(\RuntimeException::class, static function () use ($collector, $root): void {
                $collector->collect($root);
            });
            Assert::true(str_contains($e->getMessage(), 'git is required'));
        } finally {
            $this->removeDir($root);
        }
    }

    private function initRepo(string $repo): void
    {
        mkdir($repo, 0775, true);
        $this->git($repo, ['init', '-b', 'main']);
        $this->git($repo, ['config', 'user.email', 'aep@example.com']);
        $this->git($repo, ['config', 'user.name', 'AEP Test']);
        file_put_contents($repo . '/tracked.txt', "hello\n");
        $this->git($repo, ['add', 'tracked.txt']);
        $this->git($repo, ['commit', '-m', 'initial']);
    }

    /**
     * @param list<string> $args
     */
    private function git(string $repo, array $args): string
    {
        $cmd = array_merge(['git', '-C', $repo], $args);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, null, [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => sys_get_temp_dir(),
            'LANG' => 'C',
        ], ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new \RuntimeException('git start failed');
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

    /**
     * @param list<string> $args
     */
    private function gitAllowDiff(string $repo, array $args): string
    {
        $cmd = array_merge(['git', '-C', $repo], $args);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, null, [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => sys_get_temp_dir(),
            'LANG' => 'C',
        ], ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new \RuntimeException('git start failed');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 && $code !== 1) {
            throw new \RuntimeException('git failed: ' . trim($stderr !== '' ? $stderr : $stdout));
        }

        return $stdout;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/aep_diff_' . bin2hex(random_bytes(4));
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
