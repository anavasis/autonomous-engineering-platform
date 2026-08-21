<?php

declare(strict_types=1);

namespace Aep\Application\Acceptance\Model;

/**
 * Data-driven validation rule. Type selects generic evaluator behavior.
 */
final class ValidationRule
{
    public const TYPE_PROJECT_STARTED = 'project_started';
    public const TYPE_PROJECT_COMPLETED = 'project_completed';
    public const TYPE_EXECUTION_SUCCESSFUL = 'execution_successful';
    public const TYPE_RUNTIME_STATUS = 'runtime_status';
    public const TYPE_ARTIFACT_GENERATED = 'artifact_generated';
    public const TYPE_FILE_EXISTS = 'file_exists';
    public const TYPE_DIRECTORY_EXISTS = 'directory_exists';
    public const TYPE_TESTS_EXECUTED = 'tests_executed';
    public const TYPE_BUILD_COMPLETED = 'build_completed';
    public const TYPE_EXECUTION_DURATION = 'execution_duration';
    public const TYPE_EXPECTED_ARTIFACT = 'expected_artifact';

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        private string $id,
        private string $type,
        private array $params = [],
        private string $description = '',
    ) {
        $this->id = trim($id);
        $this->type = trim($type);
        if ($this->id === '' || $this->type === '') {
            throw new \InvalidArgumentException('ValidationRule id and type are required.');
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

    /** @return array<string, mixed> */
    public function params(): array
    {
        return $this->params;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'params' => $this->params,
            'description' => $this->description,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : '',
            is_string($data['type'] ?? null) ? $data['type'] : '',
            is_array($data['params'] ?? null) ? $data['params'] : [],
            is_string($data['description'] ?? null) ? $data['description'] : '',
        );
    }
}
