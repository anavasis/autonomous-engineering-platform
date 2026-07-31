<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Service;

/**
 * Collects workspace file diffs after provider execution.
 */
final class DiffCollector
{
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

        $baseline = $root . '/.baseline';
        $context = $root . '/context';
        $files = [];
        $chunks = [];

        if (is_dir($context)) {
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
                $after = (string) file_get_contents($full);
                $beforePath = $baseline . '/' . $rel;
                $before = is_file($beforePath) ? (string) file_get_contents($beforePath) : '';
                if ($before === $after) {
                    continue;
                }
                $files[] = $rel;
                $chunks[] = $this->unified($rel, $before, $after);
            }
        }

        return [
            'text' => implode("\n", $chunks),
            'files' => $files,
        ];
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
