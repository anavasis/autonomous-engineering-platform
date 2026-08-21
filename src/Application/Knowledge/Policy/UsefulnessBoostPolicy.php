<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Policy;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Model\RetrievalQuery;

final class UsefulnessBoostPolicy implements KnowledgeRetrievalPolicy
{
    public function __construct(private readonly float $weight = 0.4)
    {
    }

    public function id(): string
    {
        return 'usefulness_boost';
    }

    public function evaluate(RetrievalQuery $query, KnowledgeRecord $record, array $context = []): array
    {
        return [
            'include' => true,
            'scoreDelta' => $this->weight * $record->usefulness(),
            'reason' => 'usefulness=' . round($record->usefulness(), 3),
        ];
    }
}
