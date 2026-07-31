<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Policy;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Model\RetrievalQuery;

/**
 * Policy-driven retrieval: filter and/or score candidates.
 */
interface KnowledgeRetrievalPolicy
{
    public function id(): string;

    /**
     * @param array<string, mixed> $context embedding vectors, settings, etc.
     * @return array{include: bool, scoreDelta: float, reason: string}
     */
    public function evaluate(RetrievalQuery $query, KnowledgeRecord $record, array $context = []): array;
}
