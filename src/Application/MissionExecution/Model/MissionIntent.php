<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Model;

/**
 * Structured engineering intent derived from natural language.
 */
final class MissionIntent
{
    /**
     * @param list<string> $affectedAreas
     * @param list<string> $allowedPaths
     * @param list<string> $nonGoals
     * @param list<string> $forbiddenAreas
     * @param list<string> $testExpectations
     * @param list<string> $ambiguities
     * @param array<string, float> $confidence
     */
    public function __construct(
        private string $objective,
        private string $changeType = 'fix',
        private ?string $projectId = null,
        private ?string $projectSlug = null,
        private ?string $provider = null,
        private ?string $repository = null,
        private array $affectedAreas = [],
        private array $allowedPaths = [],
        private array $nonGoals = [],
        private array $forbiddenAreas = [],
        private array $testExpectations = [],
        private string $approvalMode = 'standard',
        private bool $inspectionFirst = true,
        private array $ambiguities = [],
        private array $confidence = [],
        private string $rawText = '',
    ) {
        $this->objective = trim($objective);
        $this->changeType = trim($changeType) !== '' ? trim($changeType) : 'fix';
        $this->approvalMode = trim($approvalMode) !== '' ? trim($approvalMode) : 'standard';
    }

    public function objective(): string
    {
        return $this->objective;
    }

    public function changeType(): string
    {
        return $this->changeType;
    }

    public function projectId(): ?string
    {
        return $this->projectId;
    }

    public function projectSlug(): ?string
    {
        return $this->projectSlug;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function repository(): ?string
    {
        return $this->repository;
    }

    /** @return list<string> */
    public function affectedAreas(): array
    {
        return $this->affectedAreas;
    }

    /** @return list<string> */
    public function allowedPaths(): array
    {
        return $this->allowedPaths;
    }

    /** @return list<string> */
    public function nonGoals(): array
    {
        return $this->nonGoals;
    }

    /** @return list<string> */
    public function forbiddenAreas(): array
    {
        return $this->forbiddenAreas;
    }

    /** @return list<string> */
    public function testExpectations(): array
    {
        return $this->testExpectations;
    }

    public function approvalMode(): string
    {
        return $this->approvalMode;
    }

    public function inspectionFirst(): bool
    {
        return $this->inspectionFirst;
    }

    /** @return list<string> */
    public function ambiguities(): array
    {
        return $this->ambiguities;
    }

    /** @return array<string, float> */
    public function confidence(): array
    {
        return $this->confidence;
    }

    public function rawText(): string
    {
        return $this->rawText;
    }

    public function withProjectId(?string $projectId): self
    {
        $clone = clone $this;
        $clone->projectId = $projectId;

        return $clone;
    }

    public function withTarget(?string $provider, ?string $repository): self
    {
        $clone = clone $this;
        $clone->provider = $provider;
        $clone->repository = $repository;

        return $clone;
    }

    public function withAllowedPaths(array $paths): self
    {
        $clone = clone $this;
        $clone->allowedPaths = array_values($paths);

        return $clone;
    }

    public function withNonGoals(array $nonGoals): self
    {
        $clone = clone $this;
        $clone->nonGoals = array_values($nonGoals);

        return $clone;
    }

    public function withAmbiguities(array $ambiguities): self
    {
        $clone = clone $this;
        $clone->ambiguities = array_values($ambiguities);

        return $clone;
    }

    public function withObjective(string $objective): self
    {
        $clone = clone $this;
        $clone->objective = trim($objective);

        return $clone;
    }

    public function mergeAnswers(array $answers): self
    {
        $clone = clone $this;
        if (isset($answers['projectId']) && is_string($answers['projectId'])) {
            $clone->projectId = $answers['projectId'];
        }
        if (isset($answers['provider']) && is_string($answers['provider'])) {
            $clone->provider = $answers['provider'];
        }
        if (isset($answers['repository']) && is_string($answers['repository'])) {
            $repo = trim($answers['repository']);
            if (str_contains($repo, ':')) {
                [$prov, $name] = explode(':', $repo, 2);
                $clone->provider = trim($prov) !== '' ? trim($prov) : $clone->provider;
                $clone->repository = trim($name);
            } else {
                $clone->repository = $repo;
            }
        }
        if (isset($answers['objective']) && is_string($answers['objective'])) {
            $clone->objective = trim($answers['objective']);
        }
        if (isset($answers['allowedPaths'])) {
            $paths = $answers['allowedPaths'];
            if (is_string($paths)) {
                $paths = array_values(array_filter(array_map('trim', explode(',', $paths))));
            }
            if (is_array($paths)) {
                $clone->allowedPaths = array_values(array_filter($paths, static fn ($p) => is_string($p) && $p !== ''));
            }
        }
        if (isset($answers['nonGoals'])) {
            $ng = $answers['nonGoals'];
            if (is_string($ng)) {
                $ng = array_values(array_filter(array_map('trim', explode(',', $ng))));
            }
            if (is_array($ng)) {
                $clone->nonGoals = array_values(array_filter($ng, static fn ($p) => is_string($p) && $p !== ''));
            }
        }
        if (isset($answers['affectedAreas'])) {
            $areas = $answers['affectedAreas'];
            if (is_string($areas)) {
                $areas = array_values(array_filter(array_map('trim', explode(',', $areas))));
            }
            if (is_array($areas)) {
                $clone->affectedAreas = array_values(array_filter($areas, static fn ($p) => is_string($p) && $p !== ''));
            }
        }

        return $clone;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'objective' => $this->objective,
            'changeType' => $this->changeType,
            'projectId' => $this->projectId,
            'projectSlug' => $this->projectSlug,
            'provider' => $this->provider,
            'repository' => $this->repository,
            'affectedAreas' => $this->affectedAreas,
            'allowedPaths' => $this->allowedPaths,
            'nonGoals' => $this->nonGoals,
            'forbiddenAreas' => $this->forbiddenAreas,
            'testExpectations' => $this->testExpectations,
            'approvalMode' => $this->approvalMode,
            'inspectionFirst' => $this->inspectionFirst,
            'ambiguities' => $this->ambiguities,
            'confidence' => $this->confidence,
            'rawText' => $this->rawText,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['objective'] ?? null) ? $data['objective'] : '',
            is_string($data['changeType'] ?? null) ? $data['changeType'] : 'fix',
            isset($data['projectId']) && is_string($data['projectId']) ? $data['projectId'] : null,
            isset($data['projectSlug']) && is_string($data['projectSlug']) ? $data['projectSlug'] : null,
            isset($data['provider']) && is_string($data['provider']) ? $data['provider'] : null,
            isset($data['repository']) && is_string($data['repository']) ? $data['repository'] : null,
            self::stringList($data['affectedAreas'] ?? []),
            self::stringList($data['allowedPaths'] ?? []),
            self::stringList($data['nonGoals'] ?? []),
            self::stringList($data['forbiddenAreas'] ?? []),
            self::stringList($data['testExpectations'] ?? []),
            is_string($data['approvalMode'] ?? null) ? $data['approvalMode'] : 'standard',
            ($data['inspectionFirst'] ?? true) === true,
            self::stringList($data['ambiguities'] ?? []),
            self::floatMap($data['confidence'] ?? []),
            is_string($data['rawText'] ?? null) ? $data['rawText'] : '',
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /** @return array<string, float> */
    private static function floatMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && (is_float($v) || is_int($v))) {
                $out[$k] = (float) $v;
            }
        }

        return $out;
    }
}
