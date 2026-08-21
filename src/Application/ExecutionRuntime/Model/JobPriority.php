<?php

declare(strict_types=1);

namespace Aep\Application\ExecutionRuntime\Model;

/**
 * Runtime job priorities. Claim order: priority DESC, createdAt ASC.
 */
final class JobPriority
{
    public const LOW = 'LOW';
    public const NORMAL = 'NORMAL';
    public const HIGH = 'HIGH';
    public const CRITICAL = 'CRITICAL';

    private const RANK = [
        self::LOW => 1,
        self::NORMAL => 2,
        self::HIGH => 3,
        self::CRITICAL => 4,
    ];

    public static function normalize(string $priority): string
    {
        $p = strtoupper(trim($priority));
        if (!isset(self::RANK[$p])) {
            throw new \InvalidArgumentException(
                'Invalid job priority. Expected LOW|NORMAL|HIGH|CRITICAL, got: ' . $priority
            );
        }

        return $p;
    }

    public static function rank(string $priority): int
    {
        return self::RANK[self::normalize($priority)];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::LOW, self::NORMAL, self::HIGH, self::CRITICAL];
    }
}
