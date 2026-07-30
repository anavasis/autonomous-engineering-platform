<?php

declare(strict_types=1);

namespace Aep\Application\Mission\Command;

/**
 * Define Mission scope policy.
 */
final class DefineScope
{
    /**
     * @param list<string> $allowedPaths
     * @param list<string> $nonGoals
     */
    public function __construct(
        private string $missionId,
        private array $allowedPaths,
        private array $nonGoals = []
    ) {
        $this->missionId = self::requireNonEmpty($missionId, 'missionId');
        if ($allowedPaths === []) {
            throw new \InvalidArgumentException('allowedPaths must be non-empty.');
        }
        foreach ($allowedPaths as $path) {
            if (!is_string($path) || trim($path) === '') {
                throw new \InvalidArgumentException('allowedPaths entries must be non-empty strings.');
            }
        }
        foreach ($nonGoals as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('nonGoals entries must be strings.');
            }
        }
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    /**
     * @return list<string>
     */
    public function allowedPaths(): array
    {
        return $this->allowedPaths;
    }

    /**
     * @return list<string>
     */
    public function nonGoals(): array
    {
        return $this->nonGoals;
    }

    private static function requireNonEmpty(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' must be non-empty.');
        }

        return $value;
    }
}
