<?php

declare(strict_types=1);

namespace Aep\Application\Artifact;

/**
 * Logical mission-run workspace descriptor.
 */
final class Workspace
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SEALED = 'sealed';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_PURGED = 'purged';

    public function __construct(
        private string $workspaceId,
        private string $missionId,
        private string $runId,
        private string $rootPath,
        private string $status,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private ?string $projectId = null,
    ) {
        $this->workspaceId = trim($workspaceId);
        $this->missionId = self::assertSafeId($missionId, 'missionId');
        $this->runId = self::assertSafeId($runId, 'runId');
        if ($this->workspaceId === '' || $this->rootPath === '') {
            throw new \InvalidArgumentException('workspaceId and rootPath are required.');
        }
        if (!in_array($this->status, [
            self::STATUS_ACTIVE,
            self::STATUS_SEALED,
            self::STATUS_ARCHIVED,
            self::STATUS_PURGED,
        ], true)) {
            throw new \InvalidArgumentException('Invalid workspace status: ' . $this->status);
        }
    }

    public static function idFor(string $missionId, string $runId): string
    {
        // Double-underscore separator so missionId/runId may contain single underscores.
        return 'ws_' . self::assertSafeId($missionId, 'missionId') . '__' . self::assertSafeId($runId, 'runId');
    }

    /**
     * @return array{0: string, 1: string} [missionId, runId]
     */
    public static function parseId(string $workspaceId): array
    {
        if (!str_starts_with($workspaceId, 'ws_')) {
            throw new \InvalidArgumentException('Invalid workspaceId: ' . $workspaceId);
        }
        $rest = substr($workspaceId, 3);
        $pos = strpos($rest, '__');
        if ($pos === false) {
            throw new \InvalidArgumentException('Invalid workspaceId: ' . $workspaceId);
        }
        $missionId = substr($rest, 0, $pos);
        $runId = substr($rest, $pos + 2);
        if ($missionId === '' || $runId === '' || str_contains($runId, '__')) {
            throw new \InvalidArgumentException('Invalid workspaceId: ' . $workspaceId);
        }

        return [self::assertSafeId($missionId, 'missionId'), self::assertSafeId($runId, 'runId')];
    }

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function projectId(): ?string
    {
        return $this->projectId;
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAtUtc(): string
    {
        return $this->createdAtUtc;
    }

    public function updatedAtUtc(): string
    {
        return $this->updatedAtUtc;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSealed(): bool
    {
        return $this->status === self::STATUS_SEALED || $this->status === self::STATUS_ARCHIVED;
    }

    public function withStatus(string $status, string $updatedAtUtc): self
    {
        return new self(
            $this->workspaceId,
            $this->missionId,
            $this->runId,
            $this->rootPath,
            $status,
            $this->createdAtUtc,
            $updatedAtUtc,
            $this->projectId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workspaceId' => $this->workspaceId,
            'missionId' => $this->missionId,
            'runId' => $this->runId,
            'projectId' => $this->projectId,
            'rootPath' => $this->rootPath,
            'status' => $this->status,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['workspaceId'] ?? null) ? $data['workspaceId'] : '',
            is_string($data['missionId'] ?? null) ? $data['missionId'] : '',
            is_string($data['runId'] ?? null) ? $data['runId'] : '',
            is_string($data['rootPath'] ?? null) ? $data['rootPath'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_ACTIVE,
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            isset($data['projectId']) && is_string($data['projectId']) ? $data['projectId'] : null,
        );
    }

    public static function assertSafeId(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value) === 1) {
            throw new \InvalidArgumentException($field . ' must be filesystem-safe [A-Za-z0-9_-].');
        }

        return $value;
    }
}
