<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Model;

final class DeploymentTarget
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        private string $targetId,
        private string $environmentId,
        private string $label,
        private ?string $serverRef = null,
        private ?string $url = null,
        private array $meta = [],
    ) {}

    public function targetId(): string { return $this->targetId; }
    public function environmentId(): string { return $this->environmentId; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'targetId' => $this->targetId,
            'environmentId' => $this->environmentId,
            'label' => $this->label,
            'serverRef' => $this->serverRef,
            'url' => $this->url,
            'meta' => $this->meta,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['targetId'] ?? null) ? $data['targetId'] : self::makeId(),
            is_string($data['environmentId'] ?? null) ? $data['environmentId'] : '',
            is_string($data['label'] ?? null) ? $data['label'] : 'target',
            is_string($data['serverRef'] ?? null) ? $data['serverRef'] : null,
            is_string($data['url'] ?? null) ? $data['url'] : null,
            is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    public static function makeId(): string { return 'tgt_' . bin2hex(random_bytes(4)); }
}
