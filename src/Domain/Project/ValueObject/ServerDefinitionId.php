<?php

declare(strict_types=1);

namespace Aep\Domain\Project\ValueObject;

final class ServerDefinitionId
{
    public function __construct(
        private string $value
    ) {
        $value = trim($value);
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value) === 1) {
            throw new \InvalidArgumentException('ServerDefinitionId must be non-empty [A-Za-z0-9_-].');
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
