<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Service;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Model\RetrievalQuery;
use Aep\Application\Knowledge\Policy\KnowledgeRetrievalPolicySet;
use Aep\Application\MissionControl\Support\Utc;

/**
 * Ranks candidates solely by evaluating configured KnowledgeRetrievalPolicy rules.
 */
final class KnowledgeRanker
{
    public function __construct(private readonly KnowledgeRetrievalPolicySet $policies)
    {
    }

    /**
     * @param list<KnowledgeRecord> $candidates
     * @param array<string, mixed> $context
     * @return list<array{record: KnowledgeRecord, score: float, trace: list<array<string, mixed>>}>
     */
    public function rank(RetrievalQuery $query, array $candidates, array $context = []): array
    {
        $context['nowUtc'] = $context['nowUtc'] ?? Utc::now();
        $scored = [];
        foreach ($candidates as $record) {
            $include = true;
            $score = 0.0;
            $trace = [];
            foreach ($this->policies->all() as $policy) {
                $result = $policy->evaluate($query, $record, $context);
                $passed = ($result['include'] ?? true) === true;
                $delta = is_numeric($result['scoreDelta'] ?? null) ? (float) $result['scoreDelta'] : 0.0;
                $trace[] = [
                    'policy' => $policy->id(),
                    'include' => $passed,
                    'scoreDelta' => $delta,
                    'reason' => is_string($result['reason'] ?? null) ? $result['reason'] : '',
                ];
                if (!$passed) {
                    $include = false;
                }
                $score += $delta;
            }
            if (!$include) {
                continue;
            }
            $scored[] = ['record' => $record, 'score' => $score, 'trace' => $trace];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $scored;
    }
}
