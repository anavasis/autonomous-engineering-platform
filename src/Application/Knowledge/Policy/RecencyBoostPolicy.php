<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Policy;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Model\RetrievalQuery;

final class RecencyBoostPolicy implements KnowledgeRetrievalPolicy
{
    public function __construct(private readonly float $weight = 0.3)
    {
    }

    public function id(): string
    {
        return 'recency_boost';
    }

    public function evaluate(RetrievalQuery $query, KnowledgeRecord $record, array $context = []): array
    {
        $created = strtotime($record->createdAtUtc() . ' UTC') ?: 0;
        $now = strtotime(($context['nowUtc'] ?? gmdate('Y-m-d\TH:i:s\Z')) . '') ?: time();
        $ageDays = max(0.0, ($now - $created) / 86400.0);
        $boost = exp(-$ageDays / 45.0);

        return [
            'include' => true,
            'scoreDelta' => $this->weight * $boost,
            'reason' => 'recency ageDays=' . round($ageDays, 1),
        ];
    }
}
