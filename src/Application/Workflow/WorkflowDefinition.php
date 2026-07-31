<?php

declare(strict_types=1);

namespace Aep\Application\Workflow;

/**
 * Declarative workflow document (Application IR).
 */
final class WorkflowDefinition
{
    /**
     * @param list<WorkflowParameterDefinition> $parameters
     * @param list<WorkflowTaskDefinition> $tasks
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $workflow,
        private string $version,
        private array $tasks,
        private array $parameters = [],
        private array $metadata = [],
        private string $sourceHash = '',
    ) {
        $this->workflow = trim($workflow);
        $this->version = trim($version);
        if ($this->workflow === '' || $this->version === '') {
            throw new \InvalidArgumentException('workflow and version are required.');
        }
        if ($this->tasks === []) {
            throw new \InvalidArgumentException('Workflow tasks must be non-empty.');
        }
        foreach ($this->tasks as $task) {
            if (!$task instanceof WorkflowTaskDefinition) {
                throw new \InvalidArgumentException('tasks must be WorkflowTaskDefinition instances.');
            }
        }
        foreach ($this->parameters as $parameter) {
            if (!$parameter instanceof WorkflowParameterDefinition) {
                throw new \InvalidArgumentException('parameters must be WorkflowParameterDefinition instances.');
            }
        }
    }

    public function workflow(): string
    {
        return $this->workflow;
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * @return list<WorkflowTaskDefinition>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * @return list<WorkflowParameterDefinition>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function sourceHash(): string
    {
        return $this->sourceHash;
    }

    public function withSourceHash(string $hash): self
    {
        $clone = clone $this;
        $clone->sourceHash = $hash;

        return $clone;
    }
}
