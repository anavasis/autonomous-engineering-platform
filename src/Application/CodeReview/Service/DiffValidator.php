<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Service;

use Aep\Application\CodeReview\Model\ChangeManifest;
use Aep\Application\CodeReview\Model\CheckRun;
use Aep\Application\MissionControl\Support\Utc;

final class DiffValidator
{
    public function __construct(
        private readonly int $maxFiles = 200,
        private readonly int $maxChurn = 5000,
    ) {
    }

    public function validate(string $diffText, ChangeManifest $manifest): CheckRun
    {
        $started = microtime(true);
        $findings = [];

        if (trim($diffText) === '' && $manifest->fileCount() === 0) {
            $findings[] = ['severity' => 'error', 'path' => null, 'message' => 'Empty diff'];
        }
        if (!$manifest->allowedPathsOk()) {
            $findings[] = ['severity' => 'error', 'path' => null, 'message' => 'Diff contains paths outside allowedPaths'];
        }
        foreach ($manifest->nonGoalsViolations() as $v) {
            $findings[] = ['severity' => 'error', 'path' => null, 'message' => $v];
        }
        if ($manifest->fileCount() > $this->maxFiles) {
            $findings[] = ['severity' => 'error', 'path' => null, 'message' => 'Too many files changed'];
        }
        if ($manifest->totalChurn() > $this->maxChurn) {
            $findings[] = ['severity' => 'error', 'path' => null, 'message' => 'Diff churn exceeds limit'];
        }

        $patterns = [
            '/(?i)(api[_-]?key|token|password|secret)\s*[:=]\s*\S+/' => 'Possible secret material in diff',
            '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/' => 'Bearer token in diff',
        ];
        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $diffText) === 1) {
                $findings[] = ['severity' => 'error', 'path' => null, 'message' => $message];
            }
        }

        $hasError = false;
        foreach ($findings as $f) {
            if ($f['severity'] === 'error') {
                $hasError = true;
                break;
            }
        }
        $status = $hasError ? 'failed' : 'passed';
        $message = $hasError ? 'Diff validation failed' : 'Diff validation passed';

        return new CheckRun(
            'chk_diff_' . bin2hex(random_bytes(4)),
            'diff',
            $status,
            $message,
            $findings,
            microtime(true) - $started,
            Utc::now(),
            'sha256:' . hash('sha256', $diffText . $status),
        );
    }
}
