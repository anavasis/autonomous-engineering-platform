<?php

declare(strict_types=1);

namespace Aep\Domain\Project\ValueObject;

final class ProjectStatus
{
    public const ACTIVE = 'active';
    public const ARCHIVED = 'archived';
    public const DISABLED = 'disabled';

    private const ALLOWED = [self::ACTIVE, self::ARCHIVED, self::DISABLED];

    public function __construct(
        private string $value
    ) {
        if (!in_array($value, self::ALLOWED, true)) {
            throw new \InvalidArgumentException('Invalid ProjectStatus: ' . $value);
        }
    }

    public static function active(): self
    {
        return new self(self::ACTIVE);
    }

    public static function archived(): self
    {
        return new self(self::ARCHIVED);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function is(string $status): bool
    {
        return $this->value === $status;
    }

    public function isArchived(): bool
    {
        return $this->value === self::ARCHIVED;
    }
}
