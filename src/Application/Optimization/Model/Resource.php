<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Model;

final class Resource
{
    public const SCHEMA_VERSION = 1;

    /** @param array<string, mixed> $limits @param array<string, mixed> $tags */
    public function __construct(
        private string $resourceId,
        private string $type,
        private string $scope,
        private string $unit,
        private array $limits = [],
        private array $tags = [],
        private string $updatedAtUtc = '',
    ) {}

    public function resourceId(): string { return $this->resourceId; }
    public function type(): string { return $this->type; }
    public function scope(): string { return $this->scope; }
    public function unit(): string { return $this->unit; }
    /** @return array<string, mixed> */
    public function limits(): array { return $this->limits; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'resourceId' => $this->resourceId,
            'type' => $this->type,
            'scope' => $this->scope,
            'unit' => $this->unit,
            'limits' => $this->limits,
            'tags' => $this->tags,
            'updatedAtUtc' => $this->updatedAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['resourceId'] ?? null) ? $data['resourceId'] : '',
            is_string($data['type'] ?? null) ? $data['type'] : '',
            is_string($data['scope'] ?? null) ? $data['scope'] : 'global',
            is_string($data['unit'] ?? null) ? $data['unit'] : 'units',
            is_array($data['limits'] ?? null) ? $data['limits'] : [],
            is_array($data['tags'] ?? null) ? $data['tags'] : [],
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
        );
    }
}
