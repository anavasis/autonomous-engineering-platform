<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringWorkspace\Model;

final class WorkspaceHealth
{
    public function __construct(
        private string $status,
        private string $message = '',
        private string $checkedAtUtc = '',
        private array $checks = [],
    ) {
        if (!in_array($status, ['ok', 'degraded', 'unavailable'], true)) {
            throw new \InvalidArgumentException('Invalid workspace health status.');
        }
    }

    public function status(): string
    {
        return $this->status;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function isAvailable(): bool
    {
        return $this->status === 'ok' || $this->status === 'degraded';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'checkedAtUtc' => $this->checkedAtUtc,
            'checks' => $this->checks,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['status'] ?? null) ? $data['status'] : 'unavailable',
            is_string($data['message'] ?? null) ? $data['message'] : '',
            is_string($data['checkedAtUtc'] ?? null) ? $data['checkedAtUtc'] : '',
            is_array($data['checks'] ?? null) ? $data['checks'] : [],
        );
    }
}
