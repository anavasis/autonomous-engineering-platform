<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Policy;

final class KnowledgeRetrievalPolicySet
{
    /** @param list<KnowledgeRetrievalPolicy> $policies */
    public function __construct(private readonly array $policies)
    {
    }

    /** @return list<KnowledgeRetrievalPolicy> */
    public function all(): array
    {
        return $this->policies;
    }
}
