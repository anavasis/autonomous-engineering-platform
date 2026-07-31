<?php

declare(strict_types=1);

namespace Aep\Domain\Project\ValueObject;

final class EnvironmentKind
{
    public const DEVELOPMENT = 'development';
    public const STAGING = 'staging';
    public const PRODUCTION = 'production';
    public const CUSTOM = 'custom';

    private const ALLOWED = [
        self::DEVELOPMENT,
        self::STAGING,
        self::PRODUCTION,
        self::CUSTOM,
    ];

    public function __construct(
        private string $value
    ) {
        if (!in_array($value, self::ALLOWED, true)) {
            throw new \InvalidArgumentException('Invalid EnvironmentKind: ' . $value);
        }
    }

    public function toString(): string
    {
        return $this->value;
    }
}
