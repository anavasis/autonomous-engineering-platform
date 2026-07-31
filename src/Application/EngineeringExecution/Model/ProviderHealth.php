<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Model;

final class ProviderHealth
{
    public function __construct(
        private string $status,
        private string $message = '',
        private string $checkedAtUtc = '',
    ) {
        if (!in_array($status, ['ok', 'degraded', 'unavailable'], true)) {
            throw new \InvalidArgumentException('Invalid provider health status.');
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
        ];
    }
}
