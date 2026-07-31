<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Port;

use Aep\Application\CodeReview\Model\Patch;

interface PatchStore
{
    public function save(Patch $patch): void;

    public function find(string $patchId): ?Patch;

    public function findActiveByRun(string $missionId, string $runId): ?Patch;

    /**
     * @return list<Patch>
     */
    public function list(?string $missionId = null, ?string $status = null, ?bool $mergeReady = null): array;

    /** @param array<string, mixed> $event */
    public function appendTimeline(string $patchId, array $event): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function timeline(string $patchId): array;
}
