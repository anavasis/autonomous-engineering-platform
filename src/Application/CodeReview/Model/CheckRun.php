<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Model;

final class CheckRun
{
    /**
     * @param list<array{severity: string, path: ?string, message: string}> $findings
     */
    public function __construct(
        private string $checkId,
        private string $kind,
        private string $status,
        private string $message = '',
        private array $findings = [],
        private float $durationSeconds = 0.0,
        private string $atUtc = '',
        private string $digest = '',
    ) {
        if (!in_array($kind, ['diff', 'static', 'tests', 'policy'], true)) {
            throw new \InvalidArgumentException('Invalid check kind.');
        }
        if (!in_array($status, ['passed', 'failed', 'skipped', 'error'], true)) {
            throw new \InvalidArgumentException('Invalid check status.');
        }
    }

    public function checkId(): string
    {
        return $this->checkId;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function passed(): bool
    {
        return $this->status === 'passed' || $this->status === 'skipped';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'checkId' => $this->checkId,
            'kind' => $this->kind,
            'status' => $this->status,
            'message' => $this->message,
            'findings' => $this->findings,
            'durationSeconds' => $this->durationSeconds,
            'atUtc' => $this->atUtc,
            'digest' => $this->digest,
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
            is_string($data['checkId'] ?? null) ? $data['checkId'] : '',
            is_string($data['kind'] ?? null) ? $data['kind'] : 'policy',
            is_string($data['status'] ?? null) ? $data['status'] : 'error',
            is_string($data['message'] ?? null) ? $data['message'] : '',
            $findings,
            is_float($data['durationSeconds'] ?? null) || is_int($data['durationSeconds'] ?? null)
                ? (float) $data['durationSeconds'] : 0.0,
            is_string($data['atUtc'] ?? null) ? $data['atUtc'] : '',
            is_string($data['digest'] ?? null) ? $data['digest'] : '',
        );
    }
}
