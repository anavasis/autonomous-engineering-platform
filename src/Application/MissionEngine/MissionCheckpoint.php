<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

/**
 * Durable engine checkpoint (cache/cursor only; Domain Mission remains SoT).
 */
final class MissionCheckpoint
{
    /**
     * @param list<string> $planStepIds
     * @param array<string, int> $attemptByStepId
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private string $runId,
        private string $missionId,
        private string $engineState,
        private array $planStepIds,
        private int $cursorIndex,
        private array $attemptByStepId,
        private int $progressPercent,
        private string $updatedAtUtc,
        private ?string $projectId = null,
        private array $attributes = [],
        private string $message = ''
    ) {
        if ($this->runId === '' || $this->missionId === '') {
            throw new \InvalidArgumentException('runId and missionId are required.');
        }
        if ($this->cursorIndex < 0) {
            throw new \InvalidArgumentException('cursorIndex must be >= 0.');
        }
        if ($this->progressPercent < 0 || $this->progressPercent > 100) {
            throw new \InvalidArgumentException('progressPercent must be 0..100.');
        }
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    public function projectId(): ?string
    {
        return $this->projectId;
    }

    public function engineState(): string
    {
        return $this->engineState;
    }

    /**
     * @return list<string>
     */
    public function planStepIds(): array
    {
        return $this->planStepIds;
    }

    public function cursorIndex(): int
    {
        return $this->cursorIndex;
    }

    /**
     * @return array<string, int>
     */
    public function attemptByStepId(): array
    {
        return $this->attemptByStepId;
    }

    public function progressPercent(): int
    {
        return $this->progressPercent;
    }

    public function updatedAtUtc(): string
    {
        return $this->updatedAtUtc;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return list<string>
     */
    public function completedStepIds(): array
    {
        if ($this->cursorIndex <= 0) {
            return [];
        }

        return array_slice($this->planStepIds, 0, min($this->cursorIndex, count($this->planStepIds)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'runId' => $this->runId,
            'missionId' => $this->missionId,
            'projectId' => $this->projectId,
            'engineState' => $this->engineState,
            'planStepIds' => $this->planStepIds,
            'cursorIndex' => $this->cursorIndex,
            'attemptByStepId' => $this->attemptByStepId,
            'progressPercent' => $this->progressPercent,
            'updatedAtUtc' => $this->updatedAtUtc,
            'attributes' => $this->attributes,
            'message' => $this->message,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $plan = $data['planStepIds'] ?? [];
        if (!is_array($plan)) {
            $plan = [];
        }
        /** @var list<string> $planIds */
        $planIds = [];
        foreach ($plan as $id) {
            if (is_string($id)) {
                $planIds[] = $id;
            }
        }

        $attempts = $data['attemptByStepId'] ?? [];
        if (!is_array($attempts)) {
            $attempts = [];
        }
        /** @var array<string, int> $attemptMap */
        $attemptMap = [];
        foreach ($attempts as $k => $v) {
            if (is_string($k) && is_int($v)) {
                $attemptMap[$k] = $v;
            } elseif (is_string($k) && is_numeric($v)) {
                $attemptMap[$k] = (int) $v;
            }
        }

        $attributes = $data['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }
        /** @var array<string, mixed> $attributes */

        return new self(
            is_string($data['runId'] ?? null) ? $data['runId'] : '',
            is_string($data['missionId'] ?? null) ? $data['missionId'] : '',
            is_string($data['engineState'] ?? null) ? $data['engineState'] : MissionRunState::PLANNED,
            $planIds,
            is_int($data['cursorIndex'] ?? null) ? $data['cursorIndex'] : 0,
            $attemptMap,
            is_int($data['progressPercent'] ?? null) ? $data['progressPercent'] : 0,
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            isset($data['projectId']) && is_string($data['projectId']) ? $data['projectId'] : null,
            $attributes,
            is_string($data['message'] ?? null) ? $data['message'] : '',
        );
    }
}
