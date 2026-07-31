<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Port;

interface EmbeddingProviderRegistry
{
    public function get(string $id): ?EmbeddingProvider;

    public function defaultProvider(): EmbeddingProvider;

    /** @return list<array<string, mixed>> */
    public function list(): array;
}
