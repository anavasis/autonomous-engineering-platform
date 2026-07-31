<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Knowledge\Embedding;

use Aep\Application\Knowledge\Port\EmbeddingProvider;

final class StubEmbeddingProvider implements EmbeddingProvider
{
    public function id(): string
    {
        return 'stub_embed';
    }

    public function displayName(): string
    {
        return 'Stub Embedding';
    }

    public function embed(array $texts): array
    {
        $out = [];
        foreach ($texts as $_) {
            $out[] = [1.0, 0.0, 0.0, 0.0];
        }

        return $out;
    }

    public function similarity(array $queryVec, array $candidateVec): float
    {
        return 0.5;
    }

    public function health(): array
    {
        return ['status' => 'ok', 'detail' => 'stub'];
    }
}
