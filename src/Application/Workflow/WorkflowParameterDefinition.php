<?php

declare(strict_types=1);

namespace Aep\Application\Workflow;

/**
 * Workflow parameter declaration.
 */
final class WorkflowParameterDefinition
{
    public function __construct(
        private string $name,
        private string $type = 'string',
        private bool $required = false,
        private mixed $default = null,
    ) {
        $this->name = trim($name);
        $this->type = trim($type);
        if ($this->name === '') {
            throw new \InvalidArgumentException('Parameter name is required.');
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function required(): bool
    {
        return $this->required;
    }

    public function default(): mixed
    {
        return $this->default;
    }
}
