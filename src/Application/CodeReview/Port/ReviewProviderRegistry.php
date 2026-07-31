<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Port;

interface ReviewProviderRegistry
{
    public function has(string $providerId): bool;

    public function get(string $providerId): ReviewProvider;

    /** @return list<ReviewProvider> */
    public function all(): array;

    /** @return list<string> */
    public function ids(): array;
}
