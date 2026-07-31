<?php

declare(strict_types=1);

namespace Aep\Application\Artifact;

/**
 * Immutable artifact descriptor (Application DTO — not a Domain object).
 */
final class Artifact
{
    public function __construct(
        private string $artifactId,
        private string $workspaceId,
        private ArtifactKind $kind,
        private string $name,
        private string $relativePath,
        private string $contentType,
        private int $byteSize,
        private string $contentHash,
        private string $createdAtUtc,
        private bool $persistent,
        private ArtifactMetadata $metadata,
    ) {
        $this->artifactId = self::assertLogicalId($artifactId);
        if ($this->workspaceId === '' || $this->name === '' || $this->relativePath === '') {
            throw new \InvalidArgumentException('workspaceId, name, and relativePath are required.');
        }
        if ($this->byteSize < 0) {
            throw new \InvalidArgumentException('byteSize must be >= 0.');
        }
        if ($this->contentHash === '') {
            throw new \InvalidArgumentException('contentHash is required.');
        }
    }

    public function artifactId(): string
    {
        return $this->artifactId;
    }

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }

    public function kind(): ArtifactKind
    {
        return $this->kind;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function relativePath(): string
    {
        return $this->relativePath;
    }

    public function contentType(): string
    {
        return $this->contentType;
    }

    public function byteSize(): int
    {
        return $this->byteSize;
    }

    public function contentHash(): string
    {
        return $this->contentHash;
    }

    public function createdAtUtc(): string
    {
        return $this->createdAtUtc;
    }

    public function persistent(): bool
    {
        return $this->persistent;
    }

    public function metadata(): ArtifactMetadata
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artifactId' => $this->artifactId,
            'workspaceId' => $this->workspaceId,
            'kind' => $this->kind->toString(),
            'name' => $this->name,
            'relativePath' => $this->relativePath,
            'contentType' => $this->contentType,
            'byteSize' => $this->byteSize,
            'contentHash' => $this->contentHash,
            'createdAtUtc' => $this->createdAtUtc,
            'persistent' => $this->persistent,
            'metadata' => $this->metadata->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $meta = isset($data['metadata']) && is_array($data['metadata'])
            ? ArtifactMetadata::fromArray($data['metadata'])
            : new ArtifactMetadata();

        return new self(
            is_string($data['artifactId'] ?? null) ? $data['artifactId'] : '',
            is_string($data['workspaceId'] ?? null) ? $data['workspaceId'] : '',
            new ArtifactKind(is_string($data['kind'] ?? null) ? $data['kind'] : ''),
            is_string($data['name'] ?? null) ? $data['name'] : '',
            is_string($data['relativePath'] ?? null) ? $data['relativePath'] : '',
            is_string($data['contentType'] ?? null) ? $data['contentType'] : 'application/octet-stream',
            is_int($data['byteSize'] ?? null) ? $data['byteSize'] : 0,
            is_string($data['contentHash'] ?? null) ? $data['contentHash'] : '',
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            (bool) ($data['persistent'] ?? true),
            $meta,
        );
    }

    public static function assertLogicalId(string $artifactId): string
    {
        $artifactId = trim($artifactId);
        if (preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9_]+)+$/', $artifactId) !== 1) {
            throw new \InvalidArgumentException(
                'artifactId must be a deterministic logical id like report.validation (got: ' . $artifactId . ').'
            );
        }

        return $artifactId;
    }
}
