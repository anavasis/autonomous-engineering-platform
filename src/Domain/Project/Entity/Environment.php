<?php

declare(strict_types=1);

namespace Aep\Domain\Project\Entity;

use Aep\Domain\Project\ValueObject\EnvironmentId;
use Aep\Domain\Project\ValueObject\EnvironmentKind;

final class Environment
{
    public function __construct(
        private EnvironmentId $id,
        private string $name,
        private EnvironmentKind $kind
    ) {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Environment name must be non-empty.');
        }
        $this->name = $name;
    }

    public function id(): EnvironmentId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function kind(): EnvironmentKind
    {
        return $this->kind;
    }
}
