<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Auth;

/**
 * RBAC roles for Mission Control.
 */
final class Role
{
    public const VIEWER = 'viewer';
    public const OPERATOR = 'operator';
    public const APPROVER = 'approver';
    public const ADMIN = 'admin';

    private const ALLOWED = [
        self::VIEWER,
        self::OPERATOR,
        self::APPROVER,
        self::ADMIN,
    ];

    private const RANK = [
        self::VIEWER => 1,
        self::OPERATOR => 2,
        self::APPROVER => 3,
        self::ADMIN => 4,
    ];

    public function __construct(
        private string $value
    ) {
        if (!in_array($value, self::ALLOWED, true)) {
            throw new \InvalidArgumentException('Invalid Role: ' . $value);
        }
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function is(string $role): bool
    {
        return $this->value === $role;
    }

    public function atLeast(string $minimum): bool
    {
        $min = new self($minimum);

        return self::RANK[$this->value] >= self::RANK[$min->value];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALLOWED;
    }
}
