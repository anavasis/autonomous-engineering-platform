<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Policy;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Model\RetrievalQuery;

final class KindBoostPolicy implements KnowledgeRetrievalPolicy
{
    /** @param array<string, float> $boosts */
    public function __construct(private readonly array $boosts = [])
    {
    }

    public function id(): string
    {
        return 'kind_boost';
    }

    public function evaluate(RetrievalQuery $query, KnowledgeRecord $record, array $context = []): array
    {
        $defaults = [
            KnowledgeRecord::KIND_CONSTRAINT => 0.15,
            KnowledgeRecord::KIND_FAILURE => 0.12,
            KnowledgeRecord::KIND_LESSON => 0.1,
            KnowledgeRecord::KIND_SUCCESS_PATTERN => 0.1,
            KnowledgeRecord::KIND_REVIEW => 0.05,
            KnowledgeRecord::KIND_VALIDATION => 0.05,
        ];
        $boosts = $this->boosts !== [] ? $this->boosts : $defaults;
        $delta = $boosts[$record->kind()] ?? 0.0;

        return [
            'include' => true,
            'scoreDelta' => $delta,
            'reason' => 'kind boost ' . $record->kind(),
        ];
    }
}
