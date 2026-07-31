<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Policy;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Model\RetrievalQuery;

final class LexicalOverlapPolicy implements KnowledgeRetrievalPolicy
{
    public function __construct(private readonly float $weight = 1.0)
    {
    }

    public function id(): string
    {
        return 'lexical_overlap';
    }

    public function evaluate(RetrievalQuery $query, KnowledgeRecord $record, array $context = []): array
    {
        $qTokens = $query->tokens();
        if ($qTokens === []) {
            return ['include' => true, 'scoreDelta' => 0.0, 'reason' => 'no query tokens'];
        }
        $hay = array_unique(array_merge(
            $record->tokens(),
            RetrievalQuery::tokenize($record->title() . ' ' . $record->summary() . ' ' . $record->body()),
            $record->tags(),
            $record->paths(),
        ));
        $hits = 0;
        foreach ($qTokens as $t) {
            if (in_array($t, $hay, true)) {
                $hits++;
            }
        }
        $ratio = $hits / max(1, count($qTokens));
        $include = $hits >= 1 || $query->mode() === 'browse';

        return [
            'include' => $include,
            'scoreDelta' => $this->weight * $ratio,
            'reason' => 'lexical hits=' . $hits,
        ];
    }
}
