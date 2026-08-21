<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Service;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\MissionControl\Support\Utc;

final class ArchivePolicy
{
    public function __construct(
        private readonly int $maxAgeDays = 180,
        private readonly float $minUsefulness = 0.15,
    ) {
    }

    public function shouldArchive(KnowledgeRecord $record, ?string $nowUtc = null): bool
    {
        if ($record->status() !== KnowledgeRecord::STATUS_ACTIVE) {
            return false;
        }
        if ($record->usefulness() < $this->minUsefulness && $record->hitCount() === 0) {
            return true;
        }
        $created = strtotime($record->createdAtUtc() . ' UTC') ?: 0;
        $now = strtotime(($nowUtc ?? Utc::now()) . '') ?: time();
        $ageDays = ($now - $created) / 86400.0;

        return $ageDays >= $this->maxAgeDays && $record->usefulness() < 0.4;
    }
}
