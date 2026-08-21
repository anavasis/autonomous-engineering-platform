<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Service;

use Aep\Application\CodeReview\Model\Patch;

final class PatchScorer
{
    /**
     * @return array{score: int, grade: string}
     */
    public function score(Patch $patch): array
    {
        $score = 0;
        $byKind = [];
        foreach ($patch->checks() as $check) {
            $byKind[$check->kind()] = $check;
        }

        foreach (['diff' => 20, 'static' => 20, 'tests' => 25] as $kind => $weight) {
            if (!isset($byKind[$kind])) {
                continue;
            }
            if ($byKind[$kind]->status() === 'passed' || $byKind[$kind]->status() === 'skipped') {
                $score += $weight;
            }
            if ($byKind[$kind]->status() === 'failed' && $kind === 'diff') {
                foreach ($byKind[$kind]->toArray()['findings'] as $f) {
                    if (($f['severity'] ?? '') === 'error' && str_contains((string) ($f['message'] ?? ''), 'secret')) {
                        return ['score' => 0, 'grade' => 'F'];
                    }
                }
            }
        }

        $approves = 0;
        $penalties = 0;
        foreach ($patch->reviews() as $review) {
            if ($review->isApprove()) {
                $approves++;
            }
            if (in_array($review->verdict(), ['reject', 'request_changes'], true)) {
                $penalties += 10;
            }
            $score += $review->toArray()['scoreDelta'];
        }
        if ($approves > 0) {
            $score += min(20, $approves * 10);
        }

        $owned = 0;
        foreach ($patch->manifest()->files() as $file) {
            if (($file['owner'] ?? 'unassigned') !== 'unassigned') {
                $owned++;
            }
        }
        if ($patch->manifest()->fileCount() > 0) {
            $score += (int) round(10 * ($owned / $patch->manifest()->fileCount()));
        }

        $churn = $patch->manifest()->totalChurn();
        if ($churn > 1000) {
            $score -= 15;
        } elseif ($churn > 400) {
            $score -= 8;
        }
        $score -= $penalties;

        $score = max(0, min(100, $score));
        $grade = match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 50 => 'D',
            default => 'F',
        };

        return ['score' => $score, 'grade' => $grade];
    }
}
