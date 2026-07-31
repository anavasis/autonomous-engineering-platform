<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Service;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\MissionControl\Support\Utc;

final class ForgetPolicy
{
    public function __construct(private readonly int $archiveTtlDays = 90)
    {
    }

    public function shouldForget(KnowledgeRecord $record, ?string $nowUtc = null): bool
    {
        if ($record->status() !== KnowledgeRecord::STATUS_ARCHIVED) {
            return false;
        }
        $archived = $record->toArray()['archivedAtUtc'] ?? null;
        if (!is_string($archived) || $archived === '') {
            return false;
        }
        $ts = strtotime($archived . ' UTC') ?: 0;
        $now = strtotime(($nowUtc ?? Utc::now()) . '') ?: time();

        return (($now - $ts) / 86400.0) >= $this->archiveTtlDays;
    }
}
