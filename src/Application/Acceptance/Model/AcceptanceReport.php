<?php

declare(strict_types=1);

namespace Aep\Application\Acceptance\Model;

final class AcceptanceReport
{
    /**
     * @param array<string, mixed> $executionSummary
     * @param list<ValidationOutcome> $passedValidations
     * @param list<ValidationOutcome> $failedValidations
     * @param list<array<string, mixed>> $producedArtifacts
     * @param array<string, mixed> $providerUsage
     * @param list<array<string, mixed>> $runtimeEvents
     */
    public function __construct(
        private string $id,
        private string $projectName,
        private bool $success,
        private array $executionSummary,
        private array $passedValidations,
        private array $failedValidations,
        private array $producedArtifacts,
        private float $executionTimeSeconds,
        private array $providerUsage,
        private array $runtimeEvents,
        private string $recommendation,
        private string $createdAtUtc,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function projectName(): string
    {
        return $this->projectName;
    }

    public function success(): bool
    {
        return $this->success;
    }

    /** @return array<string, mixed> */
    public function executionSummary(): array
    {
        return $this->executionSummary;
    }

    /** @return list<ValidationOutcome> */
    public function passedValidations(): array
    {
        return $this->passedValidations;
    }

    /** @return list<ValidationOutcome> */
    public function failedValidations(): array
    {
        return $this->failedValidations;
    }

    public function executionTimeSeconds(): float
    {
        return $this->executionTimeSeconds;
    }

    /** @return list<array<string, mixed>> */
    public function producedArtifacts(): array
    {
        return $this->producedArtifacts;
    }

    /** @return array<string, mixed> */
    public function providerUsage(): array
    {
        return $this->providerUsage;
    }

    /** @return list<array<string, mixed>> */
    public function runtimeEvents(): array
    {
        return $this->runtimeEvents;
    }

    public function recommendation(): string
    {
        return $this->recommendation;
    }

    public function createdAtUtc(): string
    {
        return $this->createdAtUtc;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'projectName' => $this->projectName,
            'success' => $this->success,
            'executionSummary' => $this->executionSummary,
            'passedValidations' => array_map(
                static fn (ValidationOutcome $o) => $o->toArray(),
                $this->passedValidations
            ),
            'failedValidations' => array_map(
                static fn (ValidationOutcome $o) => $o->toArray(),
                $this->failedValidations
            ),
            'producedArtifacts' => $this->producedArtifacts,
            'executionTimeSeconds' => $this->executionTimeSeconds,
            'providerUsage' => $this->providerUsage,
            'runtimeEvents' => $this->runtimeEvents,
            'recommendation' => $this->recommendation,
            'createdAtUtc' => $this->createdAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $passed = [];
        foreach ($data['passedValidations'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $passed[] = new ValidationOutcome(
                is_string($row['ruleId'] ?? null) ? $row['ruleId'] : '',
                is_string($row['type'] ?? null) ? $row['type'] : '',
                ($row['passed'] ?? false) === true,
                is_string($row['message'] ?? null) ? $row['message'] : '',
                is_array($row['details'] ?? null) ? $row['details'] : [],
            );
        }
        $failed = [];
        foreach ($data['failedValidations'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $failed[] = new ValidationOutcome(
                is_string($row['ruleId'] ?? null) ? $row['ruleId'] : '',
                is_string($row['type'] ?? null) ? $row['type'] : '',
                ($row['passed'] ?? false) === true,
                is_string($row['message'] ?? null) ? $row['message'] : '',
                is_array($row['details'] ?? null) ? $row['details'] : [],
            );
        }

        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : '',
            is_string($data['projectName'] ?? null) ? $data['projectName'] : '',
            ($data['success'] ?? false) === true,
            is_array($data['executionSummary'] ?? null) ? $data['executionSummary'] : [],
            $passed,
            $failed,
            is_array($data['producedArtifacts'] ?? null) ? $data['producedArtifacts'] : [],
            is_float($data['executionTimeSeconds'] ?? null)
                ? $data['executionTimeSeconds']
                : (is_int($data['executionTimeSeconds'] ?? null) ? (float) $data['executionTimeSeconds'] : 0.0),
            is_array($data['providerUsage'] ?? null) ? $data['providerUsage'] : [],
            is_array($data['runtimeEvents'] ?? null) ? $data['runtimeEvents'] : [],
            is_string($data['recommendation'] ?? null) ? $data['recommendation'] : '',
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
        );
    }
}
