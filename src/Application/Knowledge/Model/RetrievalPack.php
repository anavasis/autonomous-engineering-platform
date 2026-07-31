<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Model;

final class RetrievalPack
{
    /**
     * @param list<string> $notes
     * @param list<string> $refs
     * @param list<array<string, mixed>> $hits
     * @param list<array<string, mixed>> $similarMissions
     * @param list<array<string, mixed>> $similarPatches
     * @param list<array<string, mixed>> $lessons
     * @param list<array<string, mixed>> $policyTrace
     */
    public function __construct(
        private array $notes,
        private array $refs,
        private array $hits,
        private array $similarMissions,
        private array $similarPatches,
        private array $lessons,
        private array $policyTrace,
        private string $fingerprint,
        private string $evaluatedAtUtc,
    ) {
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'notes' => $this->notes,
            'refs' => $this->refs,
            'hits' => $this->hits,
            'similarMissions' => $this->similarMissions,
            'similarPatches' => $this->similarPatches,
            'lessons' => $this->lessons,
            'policyTrace' => $this->policyTrace,
            'fingerprint' => $this->fingerprint,
            'evaluatedAtUtc' => $this->evaluatedAtUtc,
        ];
    }
}
