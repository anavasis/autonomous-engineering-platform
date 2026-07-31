<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Model;

final class MergeReadiness
{
    /**
     * @param list<string> $blockers
     * @param list<string> $warnings
     * @param list<array{rule: string, passed: bool, message: string}> $checklist
     */
    public function __construct(
        private bool $ready,
        private array $blockers = [],
        private array $warnings = [],
        private array $checklist = [],
        private string $evaluatedAtUtc = '',
    ) {
    }

    public function ready(): bool
    {
        return $this->ready;
    }

    /** @return list<string> */
    public function blockers(): array
    {
        return $this->blockers;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'checklist' => $this->checklist,
            'evaluatedAtUtc' => $this->evaluatedAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $blockers = [];
        if (isset($data['blockers']) && is_array($data['blockers'])) {
            foreach ($data['blockers'] as $b) {
                if (is_string($b)) {
                    $blockers[] = $b;
                }
            }
        }
        $warnings = [];
        if (isset($data['warnings']) && is_array($data['warnings'])) {
            foreach ($data['warnings'] as $w) {
                if (is_string($w)) {
                    $warnings[] = $w;
                }
            }
        }
        $checklist = [];
        if (isset($data['checklist']) && is_array($data['checklist'])) {
            foreach ($data['checklist'] as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $checklist[] = [
                    'rule' => is_string($c['rule'] ?? null) ? $c['rule'] : '',
                    'passed' => ($c['passed'] ?? false) === true,
                    'message' => is_string($c['message'] ?? null) ? $c['message'] : '',
                ];
            }
        }

        return new self(
            ($data['ready'] ?? false) === true,
            $blockers,
            $warnings,
            $checklist,
            is_string($data['evaluatedAtUtc'] ?? null) ? $data['evaluatedAtUtc'] : '',
        );
    }
}
