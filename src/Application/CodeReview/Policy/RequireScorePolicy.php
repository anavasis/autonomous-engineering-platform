<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Policy;

use Aep\Application\CodeReview\Model\Patch;

final class RequireScorePolicy implements PatchPolicy
{
    public function __construct(
        private readonly int $minScore = 70,
    ) {
    }

    public function id(): string
    {
        return 'require_score';
    }

    public function evaluate(Patch $patch): array
    {
        $ok = $patch->score() >= $this->minScore;

        return [
            'passed' => $ok,
            'blocker' => $ok ? null : 'Score ' . $patch->score() . ' below threshold ' . $this->minScore,
            'warning' => null,
            'message' => $ok ? 'Score meets threshold' : 'Score too low',
        ];
    }
}
