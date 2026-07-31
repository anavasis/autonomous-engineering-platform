<?php

declare(strict_types=1);

namespace Aep\Application\Workflow;

/**
 * Static validator for WorkflowDefinition documents.
 */
final class WorkflowValidator
{
    public function __construct(
        private readonly WorkflowCatalog $catalog = new WorkflowCatalog(),
    ) {
    }

    public function validate(WorkflowDefinition $definition): WorkflowValidationResult
    {
        $errors = [];
        $warnings = [];

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $definition->workflow())) {
            $errors[] = 'workflow id is invalid.';
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/', $definition->version())) {
            $errors[] = 'version must be semver MAJOR.MINOR.PATCH.';
        }

        $paramNames = [];
        foreach ($definition->parameters() as $parameter) {
            if (isset($paramNames[$parameter->name()])) {
                $errors[] = 'Duplicate parameter: ' . $parameter->name();
            }
            $paramNames[$parameter->name()] = true;
            if ($parameter->required() && $parameter->default() === null) {
                // allowed — must be supplied at runtime
            }
        }

        $taskIds = [];
        foreach ($definition->tasks() as $index => $task) {
            if (isset($taskIds[$task->id()])) {
                $errors[] = 'Duplicate task id: ' . $task->id();
            }
            $taskIds[$task->id()] = true;

            if (!$this->catalog->has($task->type())) {
                $errors[] = 'tasks[' . $index . '] unknown type: ' . $task->type();
            }

            if ($task->type() === WorkflowCatalog::GATE_MANUAL) {
                $gateId = $task->with()['gateId'] ?? null;
                if (!is_string($gateId) || trim($gateId) === '') {
                    $errors[] = 'tasks[' . $index . '] gate.manual requires with.gateId.';
                }
            }

            if ($task->when() !== null) {
                $whenError = $this->validateWhen($task->when(), $index);
                if ($whenError !== null) {
                    $errors[] = $whenError;
                }
            }
        }

        return new WorkflowValidationResult($errors, $warnings);
    }

    /**
     * @param array<string, mixed> $when
     */
    private function validateWhen(array $when, int $index): ?string
    {
        $ops = ['eq', 'neq', 'truthy', 'falsy'];
        $keys = array_keys($when);
        if (count($keys) !== 1 || !in_array($keys[0], $ops, true)) {
            return 'tasks[' . $index . '].when must be one of eq|neq|truthy|falsy.';
        }
        $op = $keys[0];
        $value = $when[$op];
        if ($op === 'eq' || $op === 'neq') {
            if (!is_array($value) || count($value) !== 2) {
                return 'tasks[' . $index . '].when.' . $op . ' must be a 2-item list.';
            }
        }

        return null;
    }
}
