<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Policy;

interface OptimizationPolicy
{
    public function id(): string;
    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $context
     * @return array{admit: bool, scoreDelta: float, reason: string}
     */
    public function evaluate(array $intent, array $candidate, array $context = []): array;
}
