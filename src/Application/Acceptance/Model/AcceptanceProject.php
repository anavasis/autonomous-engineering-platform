<?php

declare(strict_types=1);

namespace Aep\Application\Acceptance\Model;

/**
 * Generic acceptance project definition — pure data, no project-specific logic.
 */
final class AcceptanceProject
{
    /**
     * @param list<string> $requirements
     * @param list<string> $expectedArtifacts
     * @param list<ValidationRule> $validationRules
     * @param array<string, mixed> $successCriteria
     * @param array<string, mixed> $execution launch/monitoring configuration
     */
    public function __construct(
        private string $name,
        private string $description,
        private array $requirements,
        private array $expectedArtifacts,
        private array $validationRules,
        private array $successCriteria,
        private array $execution = [],
    ) {
        $this->name = trim($name);
        if ($this->name === '') {
            throw new \InvalidArgumentException('AcceptanceProject name is required.');
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function requirements(): array
    {
        return $this->requirements;
    }

    /** @return list<string> */
    public function expectedArtifacts(): array
    {
        return $this->expectedArtifacts;
    }

    /** @return list<ValidationRule> */
    public function validationRules(): array
    {
        return $this->validationRules;
    }

    /** @return array<string, mixed> */
    public function successCriteria(): array
    {
        return $this->successCriteria;
    }

    /** @return array<string, mixed> */
    public function execution(): array
    {
        return $this->execution;
    }

    public function requirementsPrompt(): string
    {
        $prompt = is_string($this->execution['prompt'] ?? null)
            ? trim($this->execution['prompt'])
            : '';
        if ($prompt !== '') {
            return $prompt;
        }

        $lines = ['Acceptance project: ' . $this->name, '', $this->description, '', 'Requirements:'];
        foreach ($this->requirements as $req) {
            $lines[] = '- ' . $req;
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'requirements' => $this->requirements,
            'expectedArtifacts' => $this->expectedArtifacts,
            'validationRules' => array_map(
                static fn (ValidationRule $r) => $r->toArray(),
                $this->validationRules
            ),
            'successCriteria' => $this->successCriteria,
            'execution' => $this->execution,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $requirements = [];
        foreach ($data['requirements'] ?? [] as $req) {
            if (is_string($req) && $req !== '') {
                $requirements[] = $req;
            }
        }
        $artifacts = [];
        foreach ($data['expectedArtifacts'] ?? [] as $art) {
            if (is_string($art) && $art !== '') {
                $artifacts[] = $art;
            }
        }
        $rules = [];
        foreach ($data['validationRules'] ?? [] as $rule) {
            if (is_array($rule)) {
                $rules[] = ValidationRule::fromArray($rule);
            }
        }

        return new self(
            is_string($data['name'] ?? null) ? $data['name'] : '',
            is_string($data['description'] ?? null) ? $data['description'] : '',
            $requirements,
            $artifacts,
            $rules,
            is_array($data['successCriteria'] ?? null) ? $data['successCriteria'] : [],
            is_array($data['execution'] ?? null) ? $data['execution'] : [],
        );
    }
}
