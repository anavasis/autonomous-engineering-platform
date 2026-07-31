<?php

declare(strict_types=1);

namespace Aep\Application\Project\Command;

final class AddEnvironment
{
    public function __construct(
        private string $projectId,
        private string $environmentId,
        private string $name,
        private string $kind,
        private string $occurredAtUtc
    ) {
        $this->projectId = self::req($projectId, 'projectId');
        $this->environmentId = self::req($environmentId, 'environmentId');
        $this->name = self::req($name, 'name');
        $this->kind = self::req($kind, 'kind');
        $this->occurredAtUtc = self::req($occurredAtUtc, 'occurredAtUtc');
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    public function environmentId(): string
    {
        return $this->environmentId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function kind(): string
    {
        return $this->kind;
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
