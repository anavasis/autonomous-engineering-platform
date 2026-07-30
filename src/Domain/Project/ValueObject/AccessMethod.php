<?php

declare(strict_types=1);

namespace Aep\Domain\Project\ValueObject;

final class AccessMethod
{
    public const SSH = 'ssh';
    public const HTTP = 'http';
    public const LOCAL = 'local';
    public const NONE = 'none';

    private const ALLOWED = [self::SSH, self::HTTP, self::LOCAL, self::NONE];

    public function __construct(
        private string $value
    ) {
        if (!in_array($value, self::ALLOWED, true)) {
            throw new \InvalidArgumentException('Invalid AccessMethod: ' . $value);
        }
    }

    public function toString(): string
    {
        return $this->value;
    }
}
