<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Policy;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Model\RetrievalQuery;

final class KindFilterPolicy implements KnowledgeRetrievalPolicy
{
    /** @param list<string> $allowedKinds empty = all */
    public function __construct(private readonly array $allowedKinds = [])
    {
    }

    public function id(): string
    {
        return 'kind_filter';
    }

    public function evaluate(RetrievalQuery $query, KnowledgeRecord $record, array $context = []): array
    {
        $kinds = $query->kinds() !== [] ? $query->kinds() : $this->allowedKinds;
        if ($kinds === []) {
            return ['include' => true, 'scoreDelta' => 0.0, 'reason' => 'all kinds'];
        }
        $ok = in_array($record->kind(), $kinds, true);

        return [
            'include' => $ok,
            'scoreDelta' => 0.0,
            'reason' => $ok ? 'kind allowed' : 'kind filtered',
        ];
    }
}
