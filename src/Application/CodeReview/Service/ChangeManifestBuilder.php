<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Service;

use Aep\Application\CodeReview\Model\ChangeManifest;

final class ChangeManifestBuilder
{
    /**
     * @param list<string> $allowedPaths
     * @param list<string> $nonGoals
     * @param array<string, string> $ownershipRules glob-ish prefix => owner
     * @param array<string, mixed> $git
     */
    public function build(
        string $diffText,
        array $allowedPaths = ['src/'],
        array $nonGoals = [],
        array $ownershipRules = [],
        array $git = [],
    ): ChangeManifest {
        $files = [];
        $additions = 0;
        $deletions = 0;
        $paths = [];

        if (preg_match_all('/^diff --git a\/(.+?) b\/(.+)$/m', $diffText, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $row) {
                $path = $row[2];
                $paths[$path] = true;
            }
        }
        if (preg_match_all('/^\+\+\+\s+b\/(.+)$/m', $diffText, $m2) > 0) {
            foreach ($m2[1] as $path) {
                if (is_string($path) && $path !== '/dev/null') {
                    $paths[$path] = true;
                }
            }
        }
        if ($paths === [] && preg_match_all('/^--- a\/(.+)$/m', $diffText, $m3) > 0) {
            foreach ($m3[1] as $path) {
                if (is_string($path) && $path !== '/dev/null') {
                    $paths[$path] = true;
                }
            }
        }

        $additions = substr_count("\n" . $diffText, "\n+") - substr_count("\n" . $diffText, "\n+++");
        $deletions = substr_count("\n" . $diffText, "\n-") - substr_count("\n" . $diffText, "\n---");
        $additions = max(0, $additions);
        $deletions = max(0, $deletions);

        $allowedOk = true;
        foreach (array_keys($paths) as $path) {
            if (!$this->pathAllowed($path, $allowedPaths)) {
                $allowedOk = false;
            }
            $files[] = [
                'path' => $path,
                'changeType' => 'modify',
                'additions' => 0,
                'deletions' => 0,
                'owner' => $this->ownerFor($path, $ownershipRules),
            ];
        }

        $violations = [];
        foreach ($nonGoals as $ng) {
            $ng = trim($ng);
            if ($ng === '') {
                continue;
            }
            foreach ($files as $file) {
                if (str_contains(strtolower($file['path']), strtolower($ng))) {
                    $violations[] = 'Path touches non-goal "' . $ng . '": ' . $file['path'];
                }
            }
        }

        $diffHash = 'sha256:' . hash('sha256', $diffText);

        return new ChangeManifest(
            $files,
            $allowedOk,
            array_values(array_unique($violations)),
            isset($git['baseSha']) && is_string($git['baseSha']) ? $git['baseSha'] : (isset($git['headSha']) ? null : null),
            isset($git['headSha']) && is_string($git['headSha']) ? $git['headSha'] : null,
            isset($git['branch']) && is_string($git['branch']) ? $git['branch'] : null,
            $diffHash,
            $additions,
            $deletions,
        );
    }

    /**
     * @param list<string> $allowedPaths
     */
    private function pathAllowed(string $path, array $allowedPaths): bool
    {
        if ($allowedPaths === []) {
            return true;
        }
        foreach ($allowedPaths as $prefix) {
            $p = trim($prefix, '/');
            if ($p === '') {
                continue;
            }
            if ($path === $p || str_starts_with($path, $p . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $rules
     */
    private function ownerFor(string $path, array $rules): string
    {
        foreach ($rules as $pattern => $owner) {
            $prefix = rtrim(str_replace('**', '', (string) $pattern), '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return $owner;
            }
        }

        return 'unassigned';
    }
}
