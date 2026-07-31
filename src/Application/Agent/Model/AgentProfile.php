<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Model;

final class AgentProfile
{
    /**
     * @param list<AgentCapability> $capabilities
     * @param list<string> $preferredProviders
     * @param list<string> $allowedProjects
     */
    public function __construct(
        private array $capabilities = [],
        private array $preferredProviders = [],
        private float $costWeight = 1.0,
        private float $confidencePrior = 0.7,
        private int $maxConcurrency = 2,
        private array $allowedProjects = [],
    ) {
    }

    /** @return list<AgentCapability> */
    public function capabilities(): array { return $this->capabilities; }
    /** @return list<string> */
    public function preferredProviders(): array { return $this->preferredProviders; }
    public function costWeight(): float { return $this->costWeight; }
    public function confidencePrior(): float { return $this->confidencePrior; }
    public function maxConcurrency(): int { return max(1, $this->maxConcurrency); }

    /** @return list<string> */
    public function capabilityIds(): array
    {
        return array_map(static fn (AgentCapability $c) => $c->capabilityId(), $this->capabilities);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'capabilities' => array_map(static fn (AgentCapability $c) => $c->toArray(), $this->capabilities),
            'preferredProviders' => $this->preferredProviders,
            'costWeight' => $this->costWeight,
            'confidencePrior' => $this->confidencePrior,
            'maxConcurrency' => $this->maxConcurrency(),
            'allowedProjects' => $this->allowedProjects,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $caps = [];
        if (isset($data['capabilities']) && is_array($data['capabilities'])) {
            foreach ($data['capabilities'] as $c) {
                if (is_array($c)) {
                    $caps[] = AgentCapability::fromArray($c);
                }
            }
        }
        $providers = [];
        if (isset($data['preferredProviders']) && is_array($data['preferredProviders'])) {
            foreach ($data['preferredProviders'] as $p) {
                if (is_string($p)) {
                    $providers[] = $p;
                }
            }
        }
        $projects = [];
        if (isset($data['allowedProjects']) && is_array($data['allowedProjects'])) {
            foreach ($data['allowedProjects'] as $p) {
                if (is_string($p)) {
                    $projects[] = $p;
                }
            }
        }

        return new self(
            $caps,
            $providers,
            is_numeric($data['costWeight'] ?? null) ? (float) $data['costWeight'] : 1.0,
            is_numeric($data['confidencePrior'] ?? null) ? (float) $data['confidencePrior'] : 0.7,
            is_int($data['maxConcurrency'] ?? null) ? $data['maxConcurrency'] : 2,
            $projects,
        );
    }
}
