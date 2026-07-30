<?php

declare(strict_types=1);

namespace Aep\Domain\Mission\ValueObject;

/**
 * Stable mission identity.
 */
final class MissionId
{
    public function __construct(
        private string $value
    ) {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('MissionId must be non-empty.');
        }
        $this->value = $value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
