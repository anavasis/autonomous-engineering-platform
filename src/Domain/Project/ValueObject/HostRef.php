<?php

declare(strict_types=1);

namespace Aep\Domain\Project\ValueObject;

/**
 * Host reference only — never credentials.
 */
final class HostRef
{
    public function __construct(
        private string $value
    ) {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('HostRef must be non-empty.');
        }
        if (str_contains($value, '://') && str_contains($value, '@')) {
            throw new \InvalidArgumentException('HostRef must not embed credentials.');
        }
        if (str_contains($value, ' ') || str_contains($value, "\n")) {
            throw new \InvalidArgumentException('HostRef must not contain whitespace.');
        }
        $this->value = $value;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
