<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Model;

final class ExecutionCost
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        private string $costId,
        private string $providerId,
        private float $estimated,
        private float $actual = 0.0,
        private string $atUtc = '',
        private ?string $missionId = null,
        private ?string $programId = null,
        private array $meta = [],
    ) {}

    public function costId(): string { return $this->costId; }
    public function providerId(): string { return $this->providerId; }
    public function estimated(): float { return $this->estimated; }
    public function actual(): float { return $this->actual; }
    public function missionId(): ?string { return $this->missionId; }

    public function withActual(float $actual, string $atUtc): self
    {
        $c = clone $this; $c->actual = $actual; $c->atUtc = $atUtc; return $c;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'costId' => $this->costId,
            'providerId' => $this->providerId,
            'estimated' => $this->estimated,
            'actual' => $this->actual,
            'delta' => $this->actual - $this->estimated,
            'missionId' => $this->missionId,
            'programId' => $this->programId,
            'meta' => $this->meta,
            'atUtc' => $this->atUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['costId'] ?? null) ? $data['costId'] : self::makeId(),
            is_string($data['providerId'] ?? null) ? $data['providerId'] : '',
            is_numeric($data['estimated'] ?? null) ? (float) $data['estimated'] : 0.0,
            is_numeric($data['actual'] ?? null) ? (float) $data['actual'] : 0.0,
            is_string($data['atUtc'] ?? null) ? $data['atUtc'] : '',
            is_string($data['missionId'] ?? null) ? $data['missionId'] : null,
            is_string($data['programId'] ?? null) ? $data['programId'] : null,
            is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    public static function makeId(): string { return 'cost_' . bin2hex(random_bytes(6)); }
}
