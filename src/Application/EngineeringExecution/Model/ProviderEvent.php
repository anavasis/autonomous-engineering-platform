<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Model;

final class ProviderEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private int $seq,
        private string $type,
        private string $message,
        private string $atUtc,
        private array $data = [],
    ) {
        if ($seq < 0) {
            throw new \InvalidArgumentException('seq must be >= 0.');
        }
        $this->type = trim($type);
        if ($this->type === '') {
            throw new \InvalidArgumentException('event type is required.');
        }
    }

    public function seq(): int
    {
        return $this->seq;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function atUtc(): string
    {
        return $this->atUtc;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'seq' => $this->seq,
            'type' => $this->type,
            'message' => $this->message,
            'atUtc' => $this->atUtc,
            'data' => $this->data,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            is_int($row['seq'] ?? null) ? $row['seq'] : 0,
            is_string($row['type'] ?? null) ? $row['type'] : 'log',
            is_string($row['message'] ?? null) ? $row['message'] : '',
            is_string($row['atUtc'] ?? null) ? $row['atUtc'] : '',
            is_array($row['data'] ?? null) ? $row['data'] : [],
        );
    }
}
