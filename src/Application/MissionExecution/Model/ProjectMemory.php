<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Model;

/**
 * Project-specific engineering memory (not generic chat memory).
 */
final class ProjectMemory
{
    /**
     * @param array<string, string> $areaAliases map alias → path
     * @param list<string> $defaultAllowedPaths
     * @param list<string> $defaultNonGoals
     * @param list<string> $lessonsLearned
     * @param list<string> $successfulPatterns
     * @param list<string> $reusableConstraints
     * @param list<string> $engineeringKnowledge
     */
    public function __construct(
        private string $projectId,
        private array $areaAliases = [],
        private array $defaultAllowedPaths = ['src/'],
        private array $defaultNonGoals = [],
        private array $lessonsLearned = [],
        private array $successfulPatterns = [],
        private array $reusableConstraints = [],
        private array $engineeringKnowledge = [],
        private string $updatedAtUtc = '',
    ) {
        $this->projectId = trim($projectId);
        if ($this->projectId === '') {
            throw new \InvalidArgumentException('projectId is required.');
        }
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    /** @return array<string, string> */
    public function areaAliases(): array
    {
        return $this->areaAliases;
    }

    /** @return list<string> */
    public function defaultAllowedPaths(): array
    {
        return $this->defaultAllowedPaths;
    }

    /** @return list<string> */
    public function defaultNonGoals(): array
    {
        return $this->defaultNonGoals;
    }

    /** @return list<string> */
    public function lessonsLearned(): array
    {
        return $this->lessonsLearned;
    }

    /** @return list<string> */
    public function successfulPatterns(): array
    {
        return $this->successfulPatterns;
    }

    /** @return list<string> */
    public function reusableConstraints(): array
    {
        return $this->reusableConstraints;
    }

    /** @return list<string> */
    public function engineeringKnowledge(): array
    {
        return $this->engineeringKnowledge;
    }

    public function resolveAlias(string $area): ?string
    {
        $key = strtolower(trim($area));
        foreach ($this->areaAliases as $alias => $path) {
            if (strtolower($alias) === $key) {
                return $path;
            }
        }

        return null;
    }

    public function rememberLesson(string $lesson, string $atUtc): void
    {
        $lesson = trim($lesson);
        if ($lesson === '' || in_array($lesson, $this->lessonsLearned, true)) {
            return;
        }
        $this->lessonsLearned[] = $lesson;
        $this->updatedAtUtc = $atUtc;
    }

    public function rememberPattern(string $pattern, string $atUtc): void
    {
        $pattern = trim($pattern);
        if ($pattern === '' || in_array($pattern, $this->successfulPatterns, true)) {
            return;
        }
        $this->successfulPatterns[] = $pattern;
        $this->updatedAtUtc = $atUtc;
    }

    public function rememberConstraint(string $constraint, string $atUtc): void
    {
        $constraint = trim($constraint);
        if ($constraint === '' || in_array($constraint, $this->reusableConstraints, true)) {
            return;
        }
        $this->reusableConstraints[] = $constraint;
        $this->updatedAtUtc = $atUtc;
    }

    public function rememberKnowledge(string $knowledge, string $atUtc): void
    {
        $knowledge = trim($knowledge);
        if ($knowledge === '' || in_array($knowledge, $this->engineeringKnowledge, true)) {
            return;
        }
        $this->engineeringKnowledge[] = $knowledge;
        $this->updatedAtUtc = $atUtc;
    }

    public function putAlias(string $alias, string $path, string $atUtc): void
    {
        $this->areaAliases[trim($alias)] = trim($path);
        $this->updatedAtUtc = $atUtc;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'projectId' => $this->projectId,
            'areaAliases' => $this->areaAliases,
            'defaultAllowedPaths' => $this->defaultAllowedPaths,
            'defaultNonGoals' => $this->defaultNonGoals,
            'lessonsLearned' => $this->lessonsLearned,
            'successfulPatterns' => $this->successfulPatterns,
            'reusableConstraints' => $this->reusableConstraints,
            'engineeringKnowledge' => $this->engineeringKnowledge,
            'updatedAtUtc' => $this->updatedAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $aliases = [];
        if (isset($data['areaAliases']) && is_array($data['areaAliases'])) {
            foreach ($data['areaAliases'] as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $aliases[$k] = $v;
                }
            }
        }

        return new self(
            is_string($data['projectId'] ?? null) ? $data['projectId'] : '',
            $aliases,
            self::stringList($data['defaultAllowedPaths'] ?? ['src/']),
            self::stringList($data['defaultNonGoals'] ?? []),
            self::stringList($data['lessonsLearned'] ?? []),
            self::stringList($data['successfulPatterns'] ?? []),
            self::stringList($data['reusableConstraints'] ?? []),
            self::stringList($data['engineeringKnowledge'] ?? []),
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
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
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out === [] && $value === ['src/'] ? ['src/'] : $out;
    }
}
