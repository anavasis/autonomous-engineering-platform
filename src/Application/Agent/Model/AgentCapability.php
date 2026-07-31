<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Model;

final class AgentCapability
{
    /** @param list<string> $tags */
    public function __construct(
        private string $capabilityId,
        private float $proficiency = 0.8,
        private array $tags = [],
    ) {
    }

    public function capabilityId(): string { return $this->capabilityId; }
    public function proficiency(): float { return $this->proficiency; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'capabilityId' => $this->capabilityId,
            'proficiency' => $this->proficiency,
            'tags' => $this->tags,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $tags = [];
        if (isset($data['tags']) && is_array($data['tags'])) {
            foreach ($data['tags'] as $t) {
                if (is_string($t)) {
                    $tags[] = $t;
                }
            }
        }

        return new self(
            is_string($data['capabilityId'] ?? null) ? $data['capabilityId'] : '',
            is_numeric($data['proficiency'] ?? null) ? (float) $data['proficiency'] : 0.8,
            $tags,
        );
    }
}
