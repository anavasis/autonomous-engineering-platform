<?php

declare(strict_types=1);

namespace Aep\Application\Workflow;

/**
 * In-memory WorkflowLibrary for tests and embedded defaults.
 */
final class InMemoryWorkflowLibrary implements WorkflowLibrary
{
    /** @var array<string, WorkflowDefinition> keyed by "id@version" */
    private array $workflows = [];

    public function __construct(
        private readonly WorkflowParser $parser = new WorkflowParser(),
    ) {
    }

    public function registerJson(string $json): WorkflowDefinition
    {
        $definition = $this->parser->parse($json);
        $this->workflows[$this->key($definition->workflow(), $definition->version())] = $definition;

        return $definition;
    }

    public function register(WorkflowDefinition $definition): void
    {
        $this->workflows[$this->key($definition->workflow(), $definition->version())] = $definition;
    }

    public function get(string $workflowId, ?string $version = null): WorkflowDefinition
    {
        if ($version !== null) {
            $key = $this->key($workflowId, $version);
            if (!isset($this->workflows[$key])) {
                throw new \RuntimeException('Workflow not found: ' . $key);
            }

            return $this->workflows[$key];
        }

        $matches = [];
        foreach ($this->workflows as $key => $definition) {
            if ($definition->workflow() === $workflowId) {
                $matches[$key] = $definition;
            }
        }
        if ($matches === []) {
            throw new \RuntimeException('Workflow not found: ' . $workflowId);
        }
        ksort($matches);

        return $matches[array_key_last($matches)];
    }

    public function exists(string $workflowId, ?string $version = null): bool
    {
        try {
            $this->get($workflowId, $version);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function key(string $workflowId, string $version): string
    {
        return $workflowId . '@' . $version;
    }
}
