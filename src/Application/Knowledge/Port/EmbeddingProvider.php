<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Port;

interface EmbeddingProvider
{
    public function id(): string;

    public function displayName(): string;

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array;

    /**
     * @param list<float> $queryVec
     * @param list<float> $candidateVec
     */
    public function similarity(array $queryVec, array $candidateVec): float;

    /** @return array{status: string, detail: string} */
    public function health(): array;
}
