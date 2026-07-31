<?php

declare(strict_types=1);

namespace Aep\Infrastructure\CodeReview\Provider;

use Aep\Application\CodeReview\Model\Patch;
use Aep\Application\CodeReview\Model\ReviewRecord;
use Aep\Application\CodeReview\Port\ReviewProvider;
use Aep\Application\MissionControl\Support\Utc;

final class StubReviewProvider implements ReviewProvider
{
    private readonly string $providerId;
    private readonly string $name;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $this->providerId = is_string($options['id'] ?? null) ? (string) $options['id'] : 'stub-review';
        $this->name = is_string($options['displayName'] ?? null)
            ? (string) $options['displayName']
            : $this->providerId;
    }

    public function id(): string
    {
        return $this->providerId;
    }

    public function displayName(): string
    {
        return $this->name;
    }

    public function review(Patch $patch): ReviewRecord
    {
        return new ReviewRecord(
            'rev_' . $this->providerId . '_' . bin2hex(random_bytes(3)),
            $this->providerId,
            'approve',
            $this->name . ' simulated approval of ' . $patch->manifest()->diffHash(),
            [[
                'severity' => 'info',
                'path' => null,
                'message' => 'Simulated review (configure real backend later)',
            ]],
            3,
            Utc::now(),
            $this->providerId,
        );
    }
}
