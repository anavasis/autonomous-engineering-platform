<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Policy;

use Aep\Application\CodeReview\Model\Patch;

final class RequireStatusPolicy implements PatchPolicy
{
    /** @param list<string> $allowedStatuses */
    public function __construct(
        private readonly array $allowedStatuses = [Patch::STATUS_APPROVED, Patch::STATUS_MERGE_READY],
    ) {
    }

    public function id(): string
    {
        return 'require_status';
    }

    public function evaluate(Patch $patch): array
    {
        $ok = in_array($patch->status(), $this->allowedStatuses, true)
            || $patch->status() === Patch::STATUS_APPROVED
            || $patch->status() === Patch::STATUS_MERGE_READY;

        // Also allow if under_review but human/provider approved enough — readiness uses status after approve.
        $ok = in_array($patch->status(), [
            Patch::STATUS_APPROVED,
            Patch::STATUS_MERGE_READY,
            Patch::STATUS_SEALED,
        ], true);

        return [
            'passed' => $ok,
            'blocker' => $ok ? null : 'Patch status is not approved (' . $patch->status() . ')',
            'warning' => null,
            'message' => $ok ? 'Status approved' : 'Awaiting approval',
        ];
    }
}
