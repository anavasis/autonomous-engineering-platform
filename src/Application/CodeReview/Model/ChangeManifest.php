<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Model;

final class ChangeManifest
{
    /**
     * @param list<array{path: string, changeType: string, additions: int, deletions: int, owner: string}> $files
     * @param list<string> $nonGoalsViolations
     */
    public function __construct(
        private array $files = [],
        private bool $allowedPathsOk = true,
        private array $nonGoalsViolations = [],
        private ?string $baseSha = null,
        private ?string $headSha = null,
        private ?string $branch = null,
        private string $diffHash = '',
        private int $additions = 0,
        private int $deletions = 0,
    ) {
    }

    /** @return list<array{path: string, changeType: string, additions: int, deletions: int, owner: string}> */
    public function files(): array
    {
        return $this->files;
    }

    public function allowedPathsOk(): bool
    {
        return $this->allowedPathsOk;
    }

    /** @return list<string> */
    public function nonGoalsViolations(): array
    {
        return $this->nonGoalsViolations;
    }

    public function diffHash(): string
    {
        return $this->diffHash;
    }

    public function baseSha(): ?string
    {
        return $this->baseSha;
    }

    public function headSha(): ?string
    {
        return $this->headSha;
    }

    public function branch(): ?string
    {
        return $this->branch;
    }

    public function fileCount(): int
    {
        return count($this->files);
    }

    public function totalChurn(): int
    {
        return $this->additions + $this->deletions;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'files' => $this->files,
            'allowedPathsOk' => $this->allowedPathsOk,
            'nonGoalsViolations' => $this->nonGoalsViolations,
            'baseSha' => $this->baseSha,
            'headSha' => $this->headSha,
            'branch' => $this->branch,
            'diffHash' => $this->diffHash,
            'stats' => [
                'files' => $this->fileCount(),
                'additions' => $this->additions,
                'deletions' => $this->deletions,
                'churn' => $this->totalChurn(),
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $files = [];
        if (isset($data['files']) && is_array($data['files'])) {
            foreach ($data['files'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $files[] = [
                    'path' => is_string($row['path'] ?? null) ? $row['path'] : '',
                    'changeType' => is_string($row['changeType'] ?? null) ? $row['changeType'] : 'modify',
                    'additions' => is_int($row['additions'] ?? null) ? $row['additions'] : 0,
                    'deletions' => is_int($row['deletions'] ?? null) ? $row['deletions'] : 0,
                    'owner' => is_string($row['owner'] ?? null) ? $row['owner'] : 'unassigned',
                ];
            }
        }
        $violations = [];
        if (isset($data['nonGoalsViolations']) && is_array($data['nonGoalsViolations'])) {
            foreach ($data['nonGoalsViolations'] as $v) {
                if (is_string($v)) {
                    $violations[] = $v;
                }
            }
        }
        $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];

        return new self(
            $files,
            ($data['allowedPathsOk'] ?? true) === true,
            $violations,
            isset($data['baseSha']) && is_string($data['baseSha']) ? $data['baseSha'] : null,
            isset($data['headSha']) && is_string($data['headSha']) ? $data['headSha'] : null,
            isset($data['branch']) && is_string($data['branch']) ? $data['branch'] : null,
            is_string($data['diffHash'] ?? null) ? $data['diffHash'] : '',
            is_int($stats['additions'] ?? null) ? $stats['additions'] : 0,
            is_int($stats['deletions'] ?? null) ? $stats['deletions'] : 0,
        );
    }
}
