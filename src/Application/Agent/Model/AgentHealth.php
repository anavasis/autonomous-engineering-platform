<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Model;

final class AgentHealth
{
    public function __construct(
        private string $status = 'ok',
        private string $lastCheckAtUtc = '',
        private string $detail = '',
        private int $consecutiveFailures = 0,
    ) {
    }

    public function status(): string { return $this->status; }
    public function consecutiveFailures(): int { return $this->consecutiveFailures; }
    public function isHealthy(): bool { return $this->status === 'ok'; }

    public function withCheck(string $status, string $atUtc, string $detail = ''): self
    {
        $c = clone $this;
        $c->status = $status;
        $c->lastCheckAtUtc = $atUtc;
        $c->detail = $detail;
        $c->consecutiveFailures = $status === 'ok' ? 0 : $this->consecutiveFailures + 1;
        return $c;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'lastCheckAtUtc' => $this->lastCheckAtUtc,
            'detail' => $this->detail,
            'consecutiveFailures' => $this->consecutiveFailures,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['status'] ?? null) ? $data['status'] : 'ok',
            is_string($data['lastCheckAtUtc'] ?? null) ? $data['lastCheckAtUtc'] : '',
            is_string($data['detail'] ?? null) ? $data['detail'] : '',
            is_int($data['consecutiveFailures'] ?? null) ? $data['consecutiveFailures'] : 0,
        );
    }
}
