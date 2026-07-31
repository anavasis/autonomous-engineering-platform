<?php

declare(strict_types=1);

namespace Aep\Infrastructure\CodeReview\Provider;

use Aep\Application\CodeReview\Model\Patch;
use Aep\Application\CodeReview\Model\ReviewRecord;
use Aep\Application\CodeReview\Port\ReviewProvider;
use Aep\Application\MissionControl\Support\Utc;

final class HeuristicLocalReviewProvider implements ReviewProvider
{
    /** @param array<string, mixed> $options */
    public function __construct(private readonly array $options = [])
    {
    }

    public function id(): string
    {
        return is_string($this->options['id'] ?? null) ? (string) $this->options['id'] : 'heuristic-local';
    }

    public function displayName(): string
    {
        return is_string($this->options['displayName'] ?? null)
            ? (string) $this->options['displayName']
            : 'Heuristic Local Review';
    }

    public function review(Patch $patch): ReviewRecord
    {
        $findings = [];
        if ($patch->manifest()->fileCount() > 40) {
            $findings[] = [
                'severity' => 'warning',
                'path' => null,
                'message' => 'Large patch (' . $patch->manifest()->fileCount() . ' files)',
            ];
        }
        if ($patch->manifest()->totalChurn() > 800) {
            $findings[] = [
                'severity' => 'warning',
                'path' => null,
                'message' => 'High churn; consider splitting',
            ];
        }
        foreach ($patch->manifest()->files() as $file) {
            $path = $file['path'];
            if (str_starts_with($path, 'src/Domain/') || str_contains($path, 'MissionEngine')) {
                $findings[] = [
                    'severity' => 'warning',
                    'path' => $path,
                    'message' => 'Touches core domain/engine paths',
                ];
            }
        }
        $hasTestsTouch = false;
        foreach ($patch->manifest()->files() as $file) {
            if (str_starts_with($file['path'], 'tests/')) {
                $hasTestsTouch = true;
                break;
            }
        }
        if (!$hasTestsTouch && $patch->manifest()->fileCount() > 0) {
            $findings[] = [
                'severity' => 'info',
                'path' => null,
                'message' => 'No test files changed with this patch',
            ];
        }

        $verdict = 'approve';
        foreach ($findings as $f) {
            if ($f['severity'] === 'error') {
                $verdict = 'request_changes';
                break;
            }
        }
        if (!$patch->manifest()->allowedPathsOk()) {
            $verdict = 'reject';
            $findings[] = [
                'severity' => 'error',
                'path' => null,
                'message' => 'Paths outside allowedPaths',
            ];
        }

        return new ReviewRecord(
            'rev_' . $this->id() . '_' . bin2hex(random_bytes(3)),
            $this->id(),
            $verdict,
            $verdict === 'approve' ? 'Heuristic review approved' : 'Heuristic review found issues',
            $findings,
            $verdict === 'approve' ? 5 : -5,
            Utc::now(),
            $this->id(),
        );
    }
}
