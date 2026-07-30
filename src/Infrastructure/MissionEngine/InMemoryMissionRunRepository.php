<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionEngine;

use Aep\Application\MissionEngine\MissionCheckpoint;
use Aep\Application\MissionEngine\MissionRunRepository;
use Aep\Application\MissionEngine\MissionTimeline;

/**
 * In-memory MissionRunRepository for tests and local wiring.
 */
final class InMemoryMissionRunRepository implements MissionRunRepository
{
    /** @var array<string, MissionCheckpoint> */
    private array $checkpoints = [];

    /** @var array<string, MissionTimeline> */
    private array $timelines = [];

    public function save(MissionCheckpoint $checkpoint, MissionTimeline $timeline): void
    {
        $this->checkpoints[$checkpoint->runId()] = $checkpoint;
        $this->timelines[$checkpoint->runId()] = new MissionTimeline($timeline->entries());
    }

    public function getCheckpoint(string $runId): MissionCheckpoint
    {
        if (!isset($this->checkpoints[$runId])) {
            throw new \RuntimeException('Mission run not found: ' . $runId);
        }

        return $this->checkpoints[$runId];
    }

    public function getTimeline(string $runId): MissionTimeline
    {
        if (!isset($this->timelines[$runId])) {
            throw new \RuntimeException('Mission run timeline not found: ' . $runId);
        }

        return $this->timelines[$runId];
    }

    public function exists(string $runId): bool
    {
        return isset($this->checkpoints[$runId]);
    }
}
