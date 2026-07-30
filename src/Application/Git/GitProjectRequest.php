<?php

declare(strict_types=1);

namespace Aep\Application\Git;

/**
 * Shared project-scoped Git request fields.
 */
abstract class GitProjectRequest
{
    public function __construct(
        private string $projectId,
        private string $occurredAtUtc
    ) {
        $this->projectId = self::req($projectId, 'projectId');
        $this->occurredAtUtc = self::req($occurredAtUtc, 'occurredAtUtc');
        if (preg_match('/[^A-Za-z0-9_-]/', $this->projectId) === 1) {
            throw new \InvalidArgumentException('projectId must be filesystem-safe [A-Za-z0-9_-].');
        }
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAtUtc;
    }

    protected static function req(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' must be non-empty.');
        }

        return $value;
    }
}
