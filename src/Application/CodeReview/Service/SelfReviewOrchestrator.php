<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Service;

use Aep\Application\CodeReview\Model\Patch;
use Aep\Application\CodeReview\Model\ReviewRecord;
use Aep\Application\CodeReview\Port\ReviewProviderRegistry;

final class SelfReviewOrchestrator
{
    public function __construct(
        private readonly ReviewProviderRegistry $registry,
    ) {
    }

    /**
     * @param list<string>|null $providerIds
     * @return list<ReviewRecord>
     */
    public function review(Patch $patch, ?array $providerIds = null): array
    {
        $ids = $providerIds ?? $this->registry->ids();
        $out = [];
        foreach ($ids as $id) {
            if (!$this->registry->has($id)) {
                continue;
            }
            $out[] = $this->registry->get($id)->review($patch);
        }

        return $out;
    }
}
