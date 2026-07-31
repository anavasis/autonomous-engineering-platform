<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Workflow;

use Aep\Application\Workflow\WorkflowDefinition;
use Aep\Application\Workflow\WorkflowLibrary;
use Aep\Application\Workflow\WorkflowParser;

/**
 * Loads workflow JSON documents from a directory.
 *
 * Expected filenames: {workflowId}@{version}.json (slashes in id → __)
 */
final class JsonFileWorkflowLibrary implements WorkflowLibrary
{
    private readonly string $directory;

    public function __construct(
        string $directory,
        private readonly WorkflowParser $parser = new WorkflowParser(),
    ) {
        $directory = rtrim($directory, "/\\");
        if ($directory === '') {
            throw new \InvalidArgumentException('JsonFileWorkflowLibrary directory must be non-empty.');
        }
        $this->directory = $directory;
    }

    public function get(string $workflowId, ?string $version = null): WorkflowDefinition
    {
        if ($version !== null) {
            return $this->loadFile($this->pathFor($workflowId, $version));
        }

        $matches = glob($this->directory . DIRECTORY_SEPARATOR . $this->safeName($workflowId) . '@*.json');
        if ($matches === false || $matches === []) {
            throw new \RuntimeException('Workflow not found: ' . $workflowId);
        }
        sort($matches);

        return $this->loadFile($matches[array_key_last($matches)]);
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

    public function save(WorkflowDefinition $definition, string $json): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create workflow directory: ' . $this->directory);
        }
        $path = $this->pathFor($definition->workflow(), $definition->version());
        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException('Unable to write workflow file: ' . $path);
        }
    }

    private function loadFile(string $path): WorkflowDefinition
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Workflow file not found: ' . $path);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read workflow file: ' . $path);
        }

        return $this->parser->parse($raw);
    }

    private function pathFor(string $workflowId, string $version): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->safeName($workflowId) . '@' . $this->safeName($version) . '.json';
    }

    private function safeName(string $value): string
    {
        return str_replace(['/', '\\'], '__', $value);
    }
}
