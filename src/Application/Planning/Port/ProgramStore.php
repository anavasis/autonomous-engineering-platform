<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Port;

use Aep\Application\Planning\Model\PlanningEvent;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramSnapshot;

interface ProgramStore
{
    public function save(Program $program): void;

    public function find(string $programId): ?Program;

    /** @return list<Program> */
    public function list(?string $status = null, ?string $projectId = null): array;

    public function appendEvent(PlanningEvent $event): void;

    /** @return list<PlanningEvent> */
    public function events(string $programId, int $limit = 200): array;

    public function saveSnapshot(ProgramSnapshot $snapshot): void;

    /** @return list<ProgramSnapshot> */
    public function snapshots(string $programId): array;

    public function latestSnapshot(string $programId): ?ProgramSnapshot;

    public function indexMission(string $missionId, string $programId): void;

    public function findProgramIdByMission(string $missionId): ?string;
}
