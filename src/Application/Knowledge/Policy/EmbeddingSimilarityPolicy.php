<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Policy;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Model\RetrievalQuery;

final class EmbeddingSimilarityPolicy implements KnowledgeRetrievalPolicy
{
    public function __construct(private readonly float $weight = 0.8)
    {
    }

    public function id(): string
    {
        return 'embedding_similarity';
    }

    public function evaluate(RetrievalQuery $query, KnowledgeRecord $record, array $context = []): array
    {
        $sim = $context['similarity'][$record->knowledgeId()] ?? null;
        if (!is_float($sim) && !is_int($sim)) {
            return ['include' => true, 'scoreDelta' => 0.0, 'reason' => 'no embedding'];
        }
        $score = (float) $sim;

        return [
            'include' => true,
            'scoreDelta' => $this->weight * max(0.0, min(1.0, $score)),
            'reason' => 'embed sim=' . round($score, 3),
        ];
    }
}
