<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringWorkspace\Model;

final class WorkspaceQuota
{
    public function __construct(
        private int $maxBytes = 2147483648,
        private int $maxFiles = 100000,
        private int $maxDurationSeconds = 86400,
        private int $usedBytes = 0,
        private int $usedFiles = 0,
    ) {
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function maxFiles(): int
    {
        return $this->maxFiles;
    }

    public function maxDurationSeconds(): int
    {
        return $this->maxDurationSeconds;
    }

    public function usedBytes(): int
    {
        return $this->usedBytes;
    }

    public function usedFiles(): int
    {
        return $this->usedFiles;
    }

    public function setUsage(int $bytes, int $files): void
    {
        $this->usedBytes = max(0, $bytes);
        $this->usedFiles = max(0, $files);
    }

    public function exceeded(): bool
    {
        return $this->usedBytes > $this->maxBytes || $this->usedFiles > $this->maxFiles;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'maxBytes' => $this->maxBytes,
            'maxFiles' => $this->maxFiles,
            'maxDurationSeconds' => $this->maxDurationSeconds,
            'usedBytes' => $this->usedBytes,
            'usedFiles' => $this->usedFiles,
            'exceeded' => $this->exceeded(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_int($data['maxBytes'] ?? null) ? $data['maxBytes'] : 2147483648,
            is_int($data['maxFiles'] ?? null) ? $data['maxFiles'] : 100000,
            is_int($data['maxDurationSeconds'] ?? null) ? $data['maxDurationSeconds'] : 86400,
            is_int($data['usedBytes'] ?? null) ? $data['usedBytes'] : 0,
            is_int($data['usedFiles'] ?? null) ? $data['usedFiles'] : 0,
        );
    }
}
