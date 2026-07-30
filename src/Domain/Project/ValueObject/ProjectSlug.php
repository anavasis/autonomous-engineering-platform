<?php

declare(strict_types=1);

namespace Aep\Domain\Project\ValueObject;

final class ProjectSlug
{
    public function __construct(
        private string $value
    ) {
        $value = strtolower(trim($value));
        if ($value === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) !== 1) {
            throw new \InvalidArgumentException('ProjectSlug must match [a-z0-9]+(-[a-z0-9]+)*.');
        }
        $this->value = $value;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
