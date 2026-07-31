<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Model;

final class ReviewRecord
{
    /**
     * @param list<array{severity: string, path: ?string, message: string}> $findings
     */
    public function __construct(
        private string $reviewId,
        private string $providerId,
        private string $verdict,
        private string $summary = '',
        private array $findings = [],
        private int $scoreDelta = 0,
        private string $atUtc = '',
        private string $actor = '',
    ) {
        if (!in_array($verdict, ['approve', 'comment', 'request_changes', 'reject'], true)) {
            throw new \InvalidArgumentException('Invalid review verdict.');
        }
    }

    public function reviewId(): string
    {
        return $this->reviewId;
    }

    public function providerId(): string
    {
        return $this->providerId;
    }

    public function verdict(): string
    {
        return $this->verdict;
    }

    /** @return list<array{severity: string, path: ?string, message: string}> */
    public function findings(): array
    {
        return $this->findings;
    }

    public function isApprove(): bool
    {
        return $this->verdict === 'approve';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reviewId' => $this->reviewId,
            'providerId' => $this->providerId,
            'verdict' => $this->verdict,
            'summary' => $this->summary,
            'findings' => $this->findings,
            'scoreDelta' => $this->scoreDelta,
            'atUtc' => $this->atUtc,
            'actor' => $this->actor,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $findings = [];
        if (isset($data['findings']) && is_array($data['findings'])) {
            foreach ($data['findings'] as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $findings[] = [
                    'severity' => is_string($f['severity'] ?? null) ? $f['severity'] : 'info',
                    'path' => isset($f['path']) && is_string($f['path']) ? $f['path'] : null,
                    'message' => is_string($f['message'] ?? null) ? $f['message'] : '',
                ];
            }
        }

        return new self(
            is_string($data['reviewId'] ?? null) ? $data['reviewId'] : '',
            is_string($data['providerId'] ?? null) ? $data['providerId'] : '',
            is_string($data['verdict'] ?? null) ? $data['verdict'] : 'comment',
            is_string($data['summary'] ?? null) ? $data['summary'] : '',
            $findings,
            is_int($data['scoreDelta'] ?? null) ? $data['scoreDelta'] : 0,
            is_string($data['atUtc'] ?? null) ? $data['atUtc'] : '',
            is_string($data['actor'] ?? null) ? $data['actor'] : '',
        );
    }
}
