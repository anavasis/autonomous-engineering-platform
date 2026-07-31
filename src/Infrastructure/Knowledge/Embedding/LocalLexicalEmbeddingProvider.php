<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Knowledge\Embedding;

use Aep\Application\Knowledge\Port\EmbeddingProvider;

/**
 * Deterministic offline bag-of-tokens pseudo-embedding.
 */
final class LocalLexicalEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(private readonly int $dimensions = 64)
    {
    }

    public function id(): string
    {
        return 'local_lexical';
    }

    public function displayName(): string
    {
        return 'Local Lexical Embedding';
    }

    public function embed(array $texts): array
    {
        $out = [];
        foreach ($texts as $text) {
            $out[] = $this->vectorize(is_string($text) ? $text : '');
        }

        return $out;
    }

    public function similarity(array $queryVec, array $candidateVec): float
    {
        $n = min(count($queryVec), count($candidateVec), $this->dimensions);
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $nq = 0.0;
        $nc = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $q = $queryVec[$i];
            $c = $candidateVec[$i];
            $dot += $q * $c;
            $nq += $q * $q;
            $nc += $c * $c;
        }
        if ($nq <= 0.0 || $nc <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($nq) * sqrt($nc));
    }

    public function health(): array
    {
        return ['status' => 'ok', 'detail' => 'local lexical ready'];
    }

    /** @return list<float> */
    private function vectorize(string $text): array
    {
        $vec = array_fill(0, $this->dimensions, 0.0);
        $parts = preg_split('/\W+/', strtolower($text)) ?: [];
        foreach ($parts as $p) {
            if (strlen($p) < 3) {
                continue;
            }
            $idx = crc32($p) % $this->dimensions;
            if ($idx < 0) {
                $idx += $this->dimensions;
            }
            $vec[$idx] += 1.0;
        }
        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vec)));
        if ($norm > 0) {
            foreach ($vec as $i => $v) {
                $vec[$i] = $v / $norm;
            }
        }

        return $vec;
    }
}
