<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

/**
 * Persistence port for engine checkpoints and timelines.
 */
interface MissionRunRepository
{
    public function save(MissionCheckpoint $checkpoint, MissionTimeline $timeline): void;

    public function getCheckpoint(string $runId): MissionCheckpoint;

    public function getTimeline(string $runId): MissionTimeline;

    public function exists(string $runId): bool;
}
