<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Policy;

final class KnowledgeRetrievalPolicyFactory
{
    /**
     * @param array<string, mixed> $settings
     */
    public static function fromSettings(array $settings): KnowledgeRetrievalPolicySet
    {
        $weights = is_array($settings['rankWeights'] ?? null) ? $settings['rankWeights'] : [];
        $lexical = is_numeric($weights['lexical'] ?? null) ? (float) $weights['lexical'] : 1.0;
        $embed = is_numeric($weights['embedding'] ?? null) ? (float) $weights['embedding'] : 0.8;
        $recency = is_numeric($weights['recency'] ?? null) ? (float) $weights['recency'] : 0.3;
        $usefulness = is_numeric($weights['usefulness'] ?? null) ? (float) $weights['usefulness'] : 0.4;

        return new KnowledgeRetrievalPolicySet([
            new RequireActiveStatusPolicy(),
            new RequireProjectScopePolicy(),
            new KindFilterPolicy(),
            new LexicalOverlapPolicy($lexical),
            new EmbeddingSimilarityPolicy($embed),
            new RecencyBoostPolicy($recency),
            new UsefulnessBoostPolicy($usefulness),
            new KindBoostPolicy(),
        ]);
    }
}
