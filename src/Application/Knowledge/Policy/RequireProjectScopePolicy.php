<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Policy;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Model\RetrievalQuery;

final class RequireProjectScopePolicy implements KnowledgeRetrievalPolicy
{
    public function id(): string
    {
        return 'require_project_scope';
    }

    public function evaluate(RetrievalQuery $query, KnowledgeRecord $record, array $context = []): array
    {
        $projectId = $query->projectId();
        if ($projectId === null || $projectId === '') {
            return ['include' => true, 'scoreDelta' => 0.0, 'reason' => 'no project filter'];
        }
        if ($record->scope() === 'global') {
            return ['include' => true, 'scoreDelta' => 0.05, 'reason' => 'global scope'];
        }
        $ok = $record->projectId() === $projectId;

        return [
            'include' => $ok,
            'scoreDelta' => $ok ? 0.1 : 0.0,
            'reason' => $ok ? 'project match' : 'project mismatch',
        ];
    }
}
