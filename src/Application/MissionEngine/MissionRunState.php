<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

/**
 * Engine run lifecycle state (distinct from Domain MissionState).
 */
final class MissionRunState
{
    public const PLANNED = 'planned';
    public const RUNNING = 'running';
    public const WAITING = 'waiting';
    public const SUSPENDED = 'suspended';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const TIMED_OUT = 'timed_out';

    private const ALLOWED = [
        self::PLANNED,
        self::RUNNING,
        self::WAITING,
        self::SUSPENDED,
        self::COMPLETED,
        self::FAILED,
        self::TIMED_OUT,
    ];

    public function __construct(
        private string $value
    ) {
        if (!in_array($value, self::ALLOWED, true)) {
            throw new \InvalidArgumentException('Invalid MissionRunState: ' . $value);
        }
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function is(string $state): bool
    {
        return $this->value === $state;
    }

    public function isTerminal(): bool
    {
        return in_array($this->value, [self::COMPLETED, self::FAILED, self::TIMED_OUT], true);
    }
}
