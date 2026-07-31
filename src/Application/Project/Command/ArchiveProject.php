<?php

declare(strict_types=1);

namespace Aep\Application\Project\Command;

final class ArchiveProject
{
    public function __construct(
        private string $projectId,
        private string $occurredAtUtc
    ) {
        $this->projectId = self::req($projectId, 'projectId');
        $this->occurredAtUtc = self::req($occurredAtUtc, 'occurredAtUtc');
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAtUtc;
    }

    private static function req(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' must be non-empty.');
        }

        return $value;
    }
}
