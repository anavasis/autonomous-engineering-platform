<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Policy;

use Aep\Application\CodeReview\Model\Patch;

final class RequireChecksPassedPolicy implements PatchPolicy
{
    /** @param list<string> $requiredKinds */
    public function __construct(
        private readonly array $requiredKinds = ['diff', 'static', 'tests'],
    ) {
    }

    public function id(): string
    {
        return 'require_checks_passed';
    }

    public function evaluate(Patch $patch): array
    {
        $byKind = [];
        foreach ($patch->checks() as $check) {
            $byKind[$check->kind()] = $check;
        }
        foreach ($this->requiredKinds as $kind) {
            if (!isset($byKind[$kind])) {
                return [
                    'passed' => false,
                    'blocker' => 'Missing required check: ' . $kind,
                    'warning' => null,
                    'message' => 'Check ' . $kind . ' not run',
                ];
            }
            if ($byKind[$kind]->status() === 'failed' || $byKind[$kind]->status() === 'error') {
                return [
                    'passed' => false,
                    'blocker' => 'Check failed: ' . $kind . ' — ' . $byKind[$kind]->toArray()['message'],
                    'warning' => null,
                    'message' => 'Check ' . $kind . ' failed',
                ];
            }
        }

        return [
            'passed' => true,
            'blocker' => null,
            'warning' => null,
            'message' => 'Required checks passed',
        ];
    }
}
