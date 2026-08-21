<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Model;

final class UsageMetrics
{
    public function __construct(
        private int $inputTokens = 0,
        private int $outputTokens = 0,
        private float $costUsd = 0.0,
        private float $durationSeconds = 0.0,
        private int $retries = 0,
        private int $fallbackHops = 0,
        private int $filesChanged = 0,
    ) {
    }

    public function addTokens(int $input, int $output): void
    {
        $this->inputTokens += max(0, $input);
        $this->outputTokens += max(0, $output);
    }

    public function addCost(float $usd): void
    {
        $this->costUsd += max(0.0, $usd);
    }

    public function setDuration(float $seconds): void
    {
        $this->durationSeconds = max(0.0, $seconds);
    }

    public function incrementRetries(): void
    {
        $this->retries++;
    }

    public function incrementFallbackHops(): void
    {
        $this->fallbackHops++;
    }

    public function setFilesChanged(int $count): void
    {
        $this->filesChanged = max(0, $count);
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'totalTokens' => $this->totalTokens(),
            'costUsd' => $this->costUsd,
            'durationSeconds' => $this->durationSeconds,
            'retries' => $this->retries,
            'fallbackHops' => $this->fallbackHops,
            'filesChanged' => $this->filesChanged,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_int($data['inputTokens'] ?? null) ? $data['inputTokens'] : 0,
            is_int($data['outputTokens'] ?? null) ? $data['outputTokens'] : 0,
            is_float($data['costUsd'] ?? null) || is_int($data['costUsd'] ?? null) ? (float) $data['costUsd'] : 0.0,
            is_float($data['durationSeconds'] ?? null) || is_int($data['durationSeconds'] ?? null) ? (float) $data['durationSeconds'] : 0.0,
            is_int($data['retries'] ?? null) ? $data['retries'] : 0,
            is_int($data['fallbackHops'] ?? null) ? $data['fallbackHops'] : 0,
            is_int($data['filesChanged'] ?? null) ? $data['filesChanged'] : 0,
        );
    }
}
