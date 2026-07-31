<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Policy;

use Aep\Application\CodeReview\Model\Patch;

final class RequireManifestIntegrityPolicy implements PatchPolicy
{
    public function id(): string
    {
        return 'require_manifest_integrity';
    }

    public function evaluate(Patch $patch): array
    {
        $ok = $patch->manifest()->allowedPathsOk()
            && $patch->manifest()->nonGoalsViolations() === []
            && $patch->integrityHash() !== ''
            && $patch->reproFingerprint() !== '';

        return [
            'passed' => $ok,
            'blocker' => $ok ? null : 'Manifest integrity or allowed-paths/non-goals checks failed',
            'warning' => null,
            'message' => $ok ? 'Manifest integrity ok' : 'Integrity/path policy failed',
        ];
    }
}
