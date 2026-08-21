<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Service;

/**
 * Collects workspace file diffs after provider execution.
 */
final class DiffCollector
{
    /** @var null|callable(list<string>): string */
    private $commandRunner;

    /**
     * @param null|callable(list<string>): string $commandRunner
     */
    public function __construct(?callable $commandRunner = null)
    {
        $this->commandRunner = $commandRunner;
    }

    /**
     * @param list<string> $allowedPaths
     * @return array{text: string, files: list<string>}
     */
    public function collect(string $workspacePath, array $allowedPaths = []): array
    {
        $root = rtrim($workspacePath, '/');
        if ($root === '' || !is_dir($root)) {
            return ['text' => '', 'files' => []];
        }

        $providerDiff = $root . '/RESULT.diff';
        if (is_file($providerDiff)) {
            $text = (string) file_get_contents($providerDiff);
            $files = [];
            if (preg_match_all('/^\+\+\+\s+b\/(.+)$/m', $text, $m) > 0) {
                foreach ($m[1] as $path) {
                    if (is_string($path) && $path !== '') {
                        $files[] = $path;
                    }
                }
            }

            return ['text' => $text, 'files' => array_values(array_unique($files))];
        }

        $repoPath = $root . '/repo';
        if ($this->looksLikeGitWorkTree($repoPath)) {
            return $this->collectGitRepo($repoPath);
        }

        $contextCandidates = [$root . '/context', $root . '/mounts/context'];
        $baselineCandidates = [$root . '/.baseline', $root . '/.aep/baseline'];
        $files = [];
        $chunks = [];

        foreach ($contextCandidates as $context) {
            if (!is_dir($context)) {
                continue;
            }
            $baseline = $baselineCandidates[0];
            foreach ($baselineCandidates as $candidate) {
                if (is_dir($candidate)) {
                    $baseline = $candidate;
                    break;
                }
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($context, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $full = $file->getPathname();
                $rel = ltrim(substr($full, strlen($context)), '/');
                if ($rel === '' || !$this->allowed($rel, $allowedPaths)) {
                    continue;
                }
                if (in_array($rel, $files, true)) {
                    continue;
                }
                $after = (string) file_get_contents($full);
                $beforePath = $baseline . '/' . $rel;
                $before = is_file($beforePath) ? (string) file_get_contents($beforePath) : '';
                if ($before === $after) {
                    continue;
                }
                $files[] = $rel;
                $chunks[] = $this->unified($rel, $before, $after);
            }
            break;
        }

        return [
            'text' => implode("\n", $chunks),
            'files' => $files,
        ];
    }

    private function looksLikeGitWorkTree(string $repoPath): bool
    {
        if (!is_dir($repoPath)) {
            return false;
        }

        return is_dir($repoPath . '/.git') || is_file($repoPath . '/.git');
    }

    /**
     * @return array{text: string, files: list<string>}
     */
    private function collectGitRepo(string $repoPath): array
    {
        $this->assertGitAvailable();
        try {
            $inside = trim($this->run([
                'git', '-C', $repoPath, '-c', 'core.hooksPath=/dev/null',
                'rev-parse', '--is-inside-work-tree',
            ]));
        } catch (\Throwable $e) {
            throw new \RuntimeException('workspace/repo is not a valid Git working tree: ' . $e->getMessage(), 0, $e);
        }
        if ($inside !== 'true') {
            throw new \RuntimeException('workspace/repo is not a valid Git working tree.');
        }

        $indexBefore = $this->indexFingerprint($repoPath);
        $headBefore = trim($this->run([
            'git', '-C', $repoPath, '-c', 'core.hooksPath=/dev/null', 'rev-parse', 'HEAD',
        ]));

        $tracked = $this->runAllowDiff([
            'git', '-C', $repoPath,
            '-c', 'core.hooksPath=/dev/null',
            '-c', 'core.quotepath=false',
            'diff', '--find-renames', 'HEAD',
        ]);

        $untracked = [];
        $untrackedList = $this->run([
            'git', '-C', $repoPath,
            '-c', 'core.hooksPath=/dev/null',
            '-c', 'core.quotepath=false',
            'ls-files', '--others', '--exclude-standard', '-z',
        ]);
        foreach (explode("\0", $untrackedList) as $path) {
            if ($path === '') {
                continue;
            }
            $untracked[] = $this->untrackedAdditionDiff($repoPath, $path);
        }

        $chunks = [];
        if (trim($tracked) !== '') {
            $chunks[] = rtrim($tracked, "\n");
        }
        foreach ($untracked as $chunk) {
            if (trim($chunk) !== '') {
                $chunks[] = rtrim($chunk, "\n");
            }
        }
        $text = implode("\n", $chunks);
        if ($text !== '') {
            $text .= "\n";
        }

        $indexAfter = $this->indexFingerprint($repoPath);
        $headAfter = trim($this->run([
            'git', '-C', $repoPath, '-c', 'core.hooksPath=/dev/null', 'rev-parse', 'HEAD',
        ]));
        if ($indexBefore !== $indexAfter || $headBefore !== $headAfter) {
            throw new \RuntimeException('Diff collection must not modify the Git index or HEAD.');
        }

        return [
            'text' => $text,
            'files' => $this->filesFromDiff($text),
        ];
    }

    private function untrackedAdditionDiff(string $repoPath, string $relPath): string
    {
        $full = $repoPath . '/' . $relPath;
        if (!is_file($full)) {
            return '';
        }
        $contents = (string) file_get_contents($full);
        if ($this->isBinary($contents)) {
            return "diff --git a/{$relPath} b/{$relPath}\n"
                . "new file mode 100644\n"
                . "--- /dev/null\n"
                . "+++ b/{$relPath}\n"
                . "Binary files /dev/null and b/{$relPath} differ\n";
        }

        $diff = $this->runAllowDiff([
            'git', '-C', $repoPath,
            '-c', 'core.hooksPath=/dev/null',
            '-c', 'core.quotepath=false',
            'diff', '--no-index', '--', '/dev/null', $relPath,
        ]);
        // Normalize --no-index paths (a/dev/null b/rel) into stable a/rel b/rel form when needed.
        if ($diff === '') {
            return $this->unified($relPath, '', $contents);
        }

        return $diff;
    }

    private function isBinary(string $contents): bool
    {
        if ($contents === '') {
            return false;
        }
        if (str_contains($contents, "\0")) {
            return true;
        }

        return !mb_check_encoding($contents, 'UTF-8');
    }

    private function indexFingerprint(string $repoPath): string
    {
        $cached = $this->runAllowDiff([
            'git', '-C', $repoPath, '-c', 'core.hooksPath=/dev/null', 'diff', '--cached',
        ]);
        $status = $this->run([
            'git', '-C', $repoPath, '-c', 'core.hooksPath=/dev/null', 'status', '--porcelain=v1',
        ]);

        return hash('sha256', $cached . "\n" . $status);
    }

    /**
     * @return list<string>
     */
    private function filesFromDiff(string $text): array
    {
        $files = [];
        if (preg_match_all('/^\+\+\+\s+b\/(.+)$/m', $text, $m) > 0) {
            foreach ($m[1] as $path) {
                if (is_string($path) && $path !== '' && $path !== '/dev/null') {
                    $files[] = $path;
                }
            }
        }
        if (preg_match_all('/^diff --git a\/(.+) b\/(.+)$/m', $text, $m) > 0) {
            foreach ($m[2] as $path) {
                if (is_string($path) && $path !== '' && $path !== '/dev/null') {
                    $files[] = $path;
                }
            }
        }

        return array_values(array_unique($files));
    }

    private function assertGitAvailable(): void
    {
        try {
            $this->run(['git', '--version']);
        } catch (\Throwable $e) {
            throw new \RuntimeException('git is required to collect repository diffs but is not available.', 0, $e);
        }
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): string
    {
        return $this->runInternal($command, false);
    }

    /**
     * @param list<string> $command
     */
    private function runAllowDiff(array $command): string
    {
        return $this->runInternal($command, true);
    }

    /**
     * @param list<string> $command
     */
    private function runInternal(array $command, bool $allowDiffExit): string
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
        // git diff / diff --no-index return 1 when differences exist.
        if ($code !== 0 && !($allowDiffExit && $code === 1)) {
            throw new \RuntimeException('git failed: ' . trim($stderr !== '' ? $stderr : $stdout));
        }

        return $stdout;
    }

    /**
     * @param list<string> $allow
     */
    private function allowed(string $path, array $allow): bool
    {
        if ($allow === []) {
            return true;
        }
        foreach ($allow as $a) {
            $prefix = trim((string) $a, '/');
            if ($prefix === '') {
                continue;
            }
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function unified(string $path, string $before, string $after): string
    {
        $bLines = explode("\n", $before);
        $aLines = explode("\n", $after);

        return "--- a/{$path}\n+++ b/{$path}\n@@\n-"
            . implode("\n-", $bLines)
            . "\n+"
            . implode("\n+", $aLines);
    }
}
