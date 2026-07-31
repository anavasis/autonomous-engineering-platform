<?php

declare(strict_types=1);

namespace Aep\Application\Workflow;

/**
 * JSON-only workflow parser.
 */
final class WorkflowParser
{
    private const ALLOWED_ROOT_KEYS = ['workflow', 'version', 'metadata', 'parameters', 'tasks'];

    public function parse(string $json): WorkflowDefinition
    {
        $json = trim($json);
        if ($json === '') {
            throw new \InvalidArgumentException('Workflow JSON must be non-empty.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Invalid workflow JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new \InvalidArgumentException('Workflow JSON must decode to an object.');
        }

        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, self::ALLOWED_ROOT_KEYS, true)) {
                throw new \InvalidArgumentException('Unsupported workflow root key: ' . (string) $key);
            }
        }

        if (!isset($data['workflow'], $data['version'], $data['tasks'])
            || !is_string($data['workflow'])
            || !is_string($data['version'])
            || !is_array($data['tasks'])
        ) {
            throw new \InvalidArgumentException('Workflow JSON requires workflow, version, and tasks.');
        }

        $metadata = [];
        if (isset($data['metadata'])) {
            if (!is_array($data['metadata'])) {
                throw new \InvalidArgumentException('metadata must be an object.');
            }
            /** @var array<string, mixed> $metadata */
            $metadata = $data['metadata'];
        }

        $parameters = [];
        if (isset($data['parameters'])) {
            if (!is_array($data['parameters'])) {
                throw new \InvalidArgumentException('parameters must be a list.');
            }
            foreach ($data['parameters'] as $index => $row) {
                if (!is_array($row)) {
                    throw new \InvalidArgumentException('parameters[' . $index . '] must be an object.');
                }
                $name = $row['name'] ?? null;
                if (!is_string($name)) {
                    throw new \InvalidArgumentException('parameters[' . $index . '].name must be a string.');
                }
                $type = isset($row['type']) && is_string($row['type']) ? $row['type'] : 'string';
                $required = isset($row['required']) ? (bool) $row['required'] : false;
                $default = $row['default'] ?? null;
                $parameters[] = new WorkflowParameterDefinition($name, $type, $required, $default);
            }
        }

        $tasks = [];
        foreach ($data['tasks'] as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('tasks[' . $index . '] must be an object.');
            }
            $id = $row['id'] ?? null;
            $type = $row['type'] ?? null;
            if (!is_string($id) || !is_string($type)) {
                throw new \InvalidArgumentException('tasks[' . $index . '] requires string id and type.');
            }
            $with = [];
            if (isset($row['with'])) {
                if (!is_array($row['with'])) {
                    throw new \InvalidArgumentException('tasks[' . $index . '].with must be an object.');
                }
                /** @var array<string, mixed> $with */
                $with = $row['with'];
            }
            $when = null;
            if (isset($row['when'])) {
                if (!is_array($row['when'])) {
                    throw new \InvalidArgumentException('tasks[' . $index . '].when must be an object.');
                }
                /** @var array<string, mixed> $when */
                $when = $row['when'];
            }
            $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : null;
            $tasks[] = new WorkflowTaskDefinition($id, $type, $with, $when, $name);
        }

        $hash = hash('sha256', $json);

        return (new WorkflowDefinition(
            $data['workflow'],
            $data['version'],
            $tasks,
            $parameters,
            $metadata,
        ))->withSourceHash($hash);
    }
}
