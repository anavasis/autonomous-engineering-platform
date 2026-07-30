<?php

declare(strict_types=1);

namespace Aep\Domain\Mission\ValueObject;

/**
 * Change-control allow-list and explicit non-goals for a mission attempt.
 */
final class ScopePolicy
{
    /**
     * @param list<string> $allowedPaths
     * @param list<string> $nonGoals
     */
    public function __construct(
        private array $allowedPaths,
        private array $nonGoals = []
    ) {
        $paths = [];
        foreach ($allowedPaths as $path) {
            if (!is_string($path)) {
                throw new \InvalidArgumentException('ScopePolicy allowedPaths must be strings.');
            }
            $path = trim($path);
            if ($path === '') {
                throw new \InvalidArgumentException('ScopePolicy allowedPaths must not contain empty entries.');
            }
            $paths[] = $path;
        }
        $paths = array_values(array_unique($paths));
        if ($paths === []) {
            throw new \InvalidArgumentException('ScopePolicy allowedPaths must be non-empty.');
        }

        $goals = [];
        foreach ($nonGoals as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('ScopePolicy nonGoals must be strings.');
            }
            $item = trim($item);
            if ($item !== '') {
                $goals[] = $item;
            }
        }

        $this->allowedPaths = $paths;
        $this->nonGoals = array_values(array_unique($goals));
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

    public function allows(string $path): bool
    {
        return in_array($path, $this->allowedPaths, true);
    }
}
