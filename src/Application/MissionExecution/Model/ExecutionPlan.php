<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Model;

/**
 * Validated execution plan preview — does not execute.
 */
final class ExecutionPlan
{
    /**
     * @param array<string, mixed> $parameters
     * @param list<string> $affectedAreas
     * @param list<string> $constraints
     * @param list<string> $tests
     * @param array<string, mixed> $approvals
     * @param list<array{id: string, name: string}> $estimatedSteps
     * @param array<string, string> $explainability
     * @param list<string> $contextRefs
     * @param array<string, mixed> $launchAttributes
     * @param array<string, mixed|null> $agentHints
     */
    public function __construct(
        private string $id,
        private string $intakeId,
        private string $workflowId,
        private string $workflowVersion,
        private string $objective,
        private ?string $projectId,
        private ?string $provider,
        private ?string $repository,
        private array $parameters,
        private array $affectedAreas,
        private array $constraints,
        private array $tests,
        private array $approvals,
        private array $estimatedSteps,
        private int $estimatedDurationSeconds,
        private array $explainability,
        private array $contextRefs = [],
        private array $launchAttributes = [],
        private array $agentHints = [
            'architect' => null,
            'developer' => null,
            'reviewer' => null,
            'tester' => null,
            'security' => null,
        ],
        private string $createdAtUtc = '',
    ) {
        $this->id = trim($id);
        $this->intakeId = trim($intakeId);
        $this->workflowId = trim($workflowId);
        $this->workflowVersion = trim($workflowVersion);
        if ($this->id === '' || $this->intakeId === '' || $this->workflowId === '') {
            throw new \InvalidArgumentException('ExecutionPlan requires id, intakeId, workflowId.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function intakeId(): string
    {
        return $this->intakeId;
    }

    public function workflowId(): string
    {
        return $this->workflowId;
    }

    public function workflowVersion(): string
    {
        return $this->workflowVersion;
    }

    public function objective(): string
    {
        return $this->objective;
    }

    public function projectId(): ?string
    {
        return $this->projectId;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function repository(): ?string
    {
        return $this->repository;
    }

    /** @return array<string, mixed> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /** @return list<string> */
    public function constraints(): array
    {
        return $this->constraints;
    }

    /** @return array<string, mixed> */
    public function launchAttributes(): array
    {
        return $this->launchAttributes;
    }

    /** @return array<string, mixed> */
    public function toPreviewArray(): array
    {
        return [
            'id' => $this->id,
            'intakeId' => $this->intakeId,
            'project' => $this->projectId,
            'workflow' => $this->workflowId . '@' . $this->workflowVersion,
            'workflowId' => $this->workflowId,
            'workflowVersion' => $this->workflowVersion,
            'objective' => $this->objective,
            'target' => [
                'provider' => $this->provider,
                'repository' => $this->repository,
            ],
            'affectedAreas' => $this->affectedAreas,
            'constraints' => $this->constraints,
            'tests' => $this->tests,
            'approvals' => $this->approvals,
            'estimatedSteps' => $this->estimatedSteps,
            'estimatedDurationSeconds' => $this->estimatedDurationSeconds,
            'estimatedDuration' => $this->formatDuration($this->estimatedDurationSeconds),
            'explainability' => $this->explainability,
            'parameters' => $this->parameters,
            'contextRefs' => $this->contextRefs,
            'agentHints' => $this->agentHints,
            'createdAtUtc' => $this->createdAtUtc,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->toPreviewArray() + [
            'launchAttributes' => $this->launchAttributes,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : '',
            is_string($data['intakeId'] ?? null) ? $data['intakeId'] : '',
            is_string($data['workflowId'] ?? null) ? $data['workflowId'] : '',
            is_string($data['workflowVersion'] ?? null) ? $data['workflowVersion'] : '1.0.0',
            is_string($data['objective'] ?? null) ? $data['objective'] : '',
            isset($data['project']) && is_string($data['project']) ? $data['project'] : (isset($data['projectId']) && is_string($data['projectId']) ? $data['projectId'] : null),
            is_string($data['target']['provider'] ?? null) ? $data['target']['provider'] : (isset($data['provider']) && is_string($data['provider']) ? $data['provider'] : null),
            is_string($data['target']['repository'] ?? null) ? $data['target']['repository'] : (isset($data['repository']) && is_string($data['repository']) ? $data['repository'] : null),
            is_array($data['parameters'] ?? null) ? $data['parameters'] : [],
            self::stringList($data['affectedAreas'] ?? []),
            self::stringList($data['constraints'] ?? []),
            self::stringList($data['tests'] ?? []),
            is_array($data['approvals'] ?? null) ? $data['approvals'] : [],
            is_array($data['estimatedSteps'] ?? null) ? $data['estimatedSteps'] : [],
            is_int($data['estimatedDurationSeconds'] ?? null) ? $data['estimatedDurationSeconds'] : 0,
            is_array($data['explainability'] ?? null) ? $data['explainability'] : [],
            self::stringList($data['contextRefs'] ?? []),
            is_array($data['launchAttributes'] ?? null) ? $data['launchAttributes'] : [],
            is_array($data['agentHints'] ?? null) ? $data['agentHints'] : [
                'architect' => null,
                'developer' => null,
                'reviewer' => null,
                'tester' => null,
                'security' => null,
            ],
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        return $s === 0 ? $m . 'm' : $m . 'm ' . $s . 's';
    }
}
