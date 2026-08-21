<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Model;

final class ResourceAllocation
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_ALLOCATED = 'allocated';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_RELEASED = 'released';

    /** @param array<string, mixed> $meta */
    public function __construct(
        private string $reservationId,
        private string $resourceId,
        private float $units,
        private string $status,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private ?string $ownerType = null,
        private ?string $ownerId = null,
        private ?string $expiresAtUtc = null,
        private array $meta = [],
    ) {}

    public function reservationId(): string { return $this->reservationId; }
    public function resourceId(): string { return $this->resourceId; }
    public function units(): float { return $this->units; }
    public function status(): string { return $this->status; }
    public function ownerId(): ?string { return $this->ownerId; }

    public function withStatus(string $status, string $atUtc): self
    {
        $c = clone $this; $c->status = $status; $c->updatedAtUtc = $atUtc; return $c;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reservationId' => $this->reservationId,
            'resourceId' => $this->resourceId,
            'units' => $this->units,
            'status' => $this->status,
            'ownerType' => $this->ownerType,
            'ownerId' => $this->ownerId,
            'expiresAtUtc' => $this->expiresAtUtc,
            'meta' => $this->meta,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['reservationId'] ?? null) ? $data['reservationId'] : self::makeId(),
            is_string($data['resourceId'] ?? null) ? $data['resourceId'] : '',
            is_numeric($data['units'] ?? null) ? (float) $data['units'] : 0.0,
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_RESERVED,
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            is_string($data['ownerType'] ?? null) ? $data['ownerType'] : null,
            is_string($data['ownerId'] ?? null) ? $data['ownerId'] : null,
            is_string($data['expiresAtUtc'] ?? null) ? $data['expiresAtUtc'] : null,
            is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    public static function makeId(): string { return 'rsv_' . bin2hex(random_bytes(6)); }
}
