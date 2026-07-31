<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Artifact;

use Aep\Application\Artifact\Workspace;

/**
 * Retention thresholds for sealed/archived workspaces.
 */
final class RetentionPolicy
{
    public function __construct(
        private readonly int $retainCompletedDays = 30,
        private readonly int $retainFailedDays = 14,
        private readonly int $retainArchivedDays = 90,
        private readonly int $keepLatestNPerMission = 20,
    ) {
        if ($this->retainCompletedDays < 0 || $this->retainFailedDays < 0 || $this->retainArchivedDays < 0) {
            throw new \InvalidArgumentException('Retention days must be >= 0.');
        }
        if ($this->keepLatestNPerMission < 1) {
            throw new \InvalidArgumentException('keepLatestNPerMission must be >= 1.');
        }
    }

    public function retainCompletedDays(): int
    {
        return $this->retainCompletedDays;
    }

    public function retainFailedDays(): int
    {
        return $this->retainFailedDays;
    }

    public function retainArchivedDays(): int
    {
        return $this->retainArchivedDays;
    }

    public function keepLatestNPerMission(): int
    {
        return $this->keepLatestNPerMission;
    }

    /**
     * Decide whether a workspace is eligible for purge at $nowUtc (ISO-8601 / strtotime-compatible).
     */
    public function shouldPurge(Workspace $workspace, string $nowUtc): bool
    {
        if ($workspace->status() === Workspace::STATUS_ACTIVE || $workspace->status() === Workspace::STATUS_PURGED) {
            return false;
        }

        $updated = strtotime($workspace->updatedAtUtc());
        $now = strtotime($nowUtc);
        if ($updated === false || $now === false) {
            return false;
        }
        $ageDays = (int) floor(($now - $updated) / 86400);

        return match ($workspace->status()) {
            Workspace::STATUS_ARCHIVED => $ageDays >= $this->retainArchivedDays,
            Workspace::STATUS_SEALED => $ageDays >= $this->retainCompletedDays,
            default => false,
        };
    }
}
