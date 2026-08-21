<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Model;

final class Environment
{
    public const KIND_DEVELOPMENT = 'development';
    public const KIND_TESTING = 'testing';
    public const KIND_QA = 'qa';
    public const KIND_STAGING = 'staging';
    public const KIND_PRODUCTION = 'production';
    public const KIND_CUSTOM = 'custom';

    /** @param array<string, mixed> $meta */
    public function __construct(
        private string $environmentId,
        private string $name,
        private string $kind,
        private int $promotionOrder = 0,
        private ?string $projectId = null,
        private array $meta = [],
    ) {}

    public function environmentId(): string { return $this->environmentId; }
    public function name(): string { return $this->name; }
    public function kind(): string { return $this->kind; }
    public function promotionOrder(): int { return $this->promotionOrder; }
    public function isProduction(): bool { return $this->kind === self::KIND_PRODUCTION; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'environmentId' => $this->environmentId,
            'name' => $this->name,
            'kind' => $this->kind,
            'promotionOrder' => $this->promotionOrder,
            'projectId' => $this->projectId,
            'meta' => $this->meta,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['environmentId'] ?? null) ? $data['environmentId'] : self::makeId(),
            is_string($data['name'] ?? null) ? $data['name'] : 'Environment',
            is_string($data['kind'] ?? null) ? $data['kind'] : self::KIND_CUSTOM,
            is_int($data['promotionOrder'] ?? null) ? $data['promotionOrder'] : 0,
            is_string($data['projectId'] ?? null) ? $data['projectId'] : null,
            is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    public static function makeId(): string { return 'env_' . bin2hex(random_bytes(4)); }
}
