<?php

declare(strict_types=1);

namespace Aep\Application\Artifact;

/**
 * Non-Domain metadata bag for an artifact.
 */
final class ArtifactMetadata
{
    /**
     * @param array<string, string> $labels
     * @param array<string, mixed> $custom
     * @param list<string> $relatedRefs
     */
    public function __construct(
        private string $missionId = '',
        private string $runId = '',
        private ?string $projectId = null,
        private ?string $workflowId = null,
        private ?string $workflowVersion = null,
        private ?string $workflowHash = null,
        private ?string $stepId = null,
        private ?string $producedBy = null,
        private array $labels = [],
        private array $relatedRefs = [],
        private array $custom = [],
    ) {
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function projectId(): ?string
    {
        return $this->projectId;
    }

    public function workflowId(): ?string
    {
        return $this->workflowId;
    }

    public function workflowVersion(): ?string
    {
        return $this->workflowVersion;
    }

    public function workflowHash(): ?string
    {
        return $this->workflowHash;
    }

    public function stepId(): ?string
    {
        return $this->stepId;
    }

    public function producedBy(): ?string
    {
        return $this->producedBy;
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return $this->labels;
    }

    /**
     * @return list<string>
     */
    public function relatedRefs(): array
    {
        return $this->relatedRefs;
    }

    /**
     * @return array<string, mixed>
     */
    public function custom(): array
    {
        return $this->custom;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'missionId' => $this->missionId,
            'runId' => $this->runId,
            'projectId' => $this->projectId,
            'workflowId' => $this->workflowId,
            'workflowVersion' => $this->workflowVersion,
            'workflowHash' => $this->workflowHash,
            'stepId' => $this->stepId,
            'producedBy' => $this->producedBy,
            'labels' => $this->labels,
            'relatedRefs' => $this->relatedRefs,
            'custom' => $this->custom,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $labels = [];
        if (isset($data['labels']) && is_array($data['labels'])) {
            foreach ($data['labels'] as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $labels[$k] = $v;
                }
            }
        }
        $related = [];
        if (isset($data['relatedRefs']) && is_array($data['relatedRefs'])) {
            foreach ($data['relatedRefs'] as $ref) {
                if (is_string($ref)) {
                    $related[] = $ref;
                }
            }
        }
        $custom = [];
        if (isset($data['custom']) && is_array($data['custom'])) {
            /** @var array<string, mixed> $custom */
            $custom = $data['custom'];
        }

        return new self(
            is_string($data['missionId'] ?? null) ? $data['missionId'] : '',
            is_string($data['runId'] ?? null) ? $data['runId'] : '',
            isset($data['projectId']) && is_string($data['projectId']) ? $data['projectId'] : null,
            isset($data['workflowId']) && is_string($data['workflowId']) ? $data['workflowId'] : null,
            isset($data['workflowVersion']) && is_string($data['workflowVersion']) ? $data['workflowVersion'] : null,
            isset($data['workflowHash']) && is_string($data['workflowHash']) ? $data['workflowHash'] : null,
            isset($data['stepId']) && is_string($data['stepId']) ? $data['stepId'] : null,
            isset($data['producedBy']) && is_string($data['producedBy']) ? $data['producedBy'] : null,
            $labels,
            $related,
            $custom,
        );
    }
}
