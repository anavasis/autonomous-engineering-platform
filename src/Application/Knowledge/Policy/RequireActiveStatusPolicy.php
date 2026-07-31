<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Policy;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Model\RetrievalQuery;

final class RequireActiveStatusPolicy implements KnowledgeRetrievalPolicy
{
    public function id(): string
    {
        return 'require_active_status';
    }

    public function evaluate(RetrievalQuery $query, KnowledgeRecord $record, array $context = []): array
    {
        $ok = $record->status() === KnowledgeRecord::STATUS_ACTIVE;

        return [
            'include' => $ok,
            'scoreDelta' => 0.0,
            'reason' => $ok ? 'active' : 'status=' . $record->status(),
        ];
    }
}
