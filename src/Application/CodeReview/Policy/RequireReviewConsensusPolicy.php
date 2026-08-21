<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Policy;

use Aep\Application\CodeReview\Model\Patch;

final class RequireReviewConsensusPolicy implements PatchPolicy
{
    public function __construct(
        private readonly string $mode = 'any',
        private readonly int $quorum = 1,
        private readonly bool $requireHuman = false,
    ) {
    }

    public function id(): string
    {
        return 'require_review_consensus';
    }

    public function evaluate(Patch $patch): array
    {
        $approves = 0;
        $rejects = 0;
        $hasHumanApprove = false;
        $hasRequestChanges = false;
        foreach ($patch->reviews() as $review) {
            if ($review->verdict() === 'approve') {
                $approves++;
                if ($review->providerId() === 'human') {
                    $hasHumanApprove = true;
                }
            }
            if ($review->verdict() === 'reject') {
                $rejects++;
            }
            if ($review->verdict() === 'request_changes') {
                $hasRequestChanges = true;
            }
        }

        if ($rejects > 0) {
            return [
                'passed' => false,
                'blocker' => 'At least one review rejected the patch',
                'warning' => null,
                'message' => 'Rejected by reviewer',
            ];
        }
        if ($hasRequestChanges) {
            return [
                'passed' => false,
                'blocker' => 'Changes requested by a reviewer',
                'warning' => null,
                'message' => 'Changes requested',
            ];
        }
        if ($this->requireHuman && !$hasHumanApprove) {
            return [
                'passed' => false,
                'blocker' => 'Human approval required',
                'warning' => null,
                'message' => 'Awaiting human approval',
            ];
        }

        $ok = match ($this->mode) {
            'all' => $approves > 0 && $approves === count($patch->reviews()),
            'quorum' => $approves >= $this->quorum,
            default => $approves >= 1,
        };

        return [
            'passed' => $ok,
            'blocker' => $ok ? null : 'Review consensus not met (mode=' . $this->mode . ')',
            'warning' => $approves === 0 ? 'No approving reviews yet' : null,
            'message' => $ok ? 'Review consensus satisfied' : 'Insufficient approvals',
        ];
    }
}
