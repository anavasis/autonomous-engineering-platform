<?php

declare(strict_types=1);

namespace Aep\Application\Workflow;

/**
 * Single task entry inside a WorkflowDefinition.
 */
final class WorkflowTaskDefinition
{
    /**
     * @param array<string, mixed> $with
     * @param array<string, mixed>|null $when
     */
    public function __construct(
        private string $id,
        private string $type,
        private array $with = [],
        private ?array $when = null,
        private ?string $name = null,
    ) {
        $this->id = trim($id);
        $this->type = trim($type);
        if ($this->id === '' || $this->type === '') {
            throw new \InvalidArgumentException('Task id and type are required.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return $this->with;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function when(): ?array
    {
        return $this->when;
    }

    public function name(): ?string
    {
        return $this->name;
    }
}
