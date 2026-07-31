<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Model;

final class OptimizationDecision
{
    /**
     * @param list<string> $fallbackProviders
     * @param list<string> $fallbackAgentIds
     * @param list<string> $reservationIds
     * @param list<array<string, mixed>> $policyTrace
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private string $decisionId,
        private bool $admit,
        private string $mode,
        private float $score,
        private string $atUtc,
        private ?string $selectedProviderId = null,
        private array $fallbackProviders = [],
        private ?string $preferredAgentId = null,
        private array $fallbackAgentIds = [],
        private ?string $delayUntilUtc = null,
        private array $reservationIds = [],
        private array $policyTrace = [],
        private float $estimatedCost = 0.0,
        private ?string $programId = null,
        private ?string $nodeId = null,
        private ?string $missionId = null,
        private string $reason = '',
        private array $meta = [],
        private string $fingerprint = '',
    ) {}

    public function decisionId(): string { return $this->decisionId; }
    public function admit(): bool { return $this->admit; }
    public function selectedProviderId(): ?string { return $this->selectedProviderId; }
    /** @return list<string> */
    public function fallbackProviders(): array { return $this->fallbackProviders; }
    public function preferredAgentId(): ?string { return $this->preferredAgentId; }
    public function delayUntilUtc(): ?string { return $this->delayUntilUtc; }
    public function estimatedCost(): float { return $this->estimatedCost; }
    /** @return list<string> */
    public function reservationIds(): array { return $this->reservationIds; }
    public function score(): float { return $this->score; }
    public function reason(): string { return $this->reason; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'decisionId' => $this->decisionId,
            'admit' => $this->admit,
            'mode' => $this->mode,
            'score' => $this->score,
            'selectedProviderId' => $this->selectedProviderId,
            'fallbackProviders' => $this->fallbackProviders,
            'preferredAgentId' => $this->preferredAgentId,
            'fallbackAgentIds' => $this->fallbackAgentIds,
            'delayUntilUtc' => $this->delayUntilUtc,
            'reservationIds' => $this->reservationIds,
            'policyTrace' => $this->policyTrace,
            'estimatedCost' => $this->estimatedCost,
            'programId' => $this->programId,
            'nodeId' => $this->nodeId,
            'missionId' => $this->missionId,
            'reason' => $this->reason,
            'meta' => $this->meta,
            'fingerprint' => $this->fingerprint !== '' ? $this->fingerprint : ('sha256:' . hash('sha256', $this->decisionId . $this->atUtc)),
            'atUtc' => $this->atUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $fallbacks = [];
        foreach (is_array($data['fallbackProviders'] ?? null) ? $data['fallbackProviders'] : [] as $p) {
            if (is_string($p)) { $fallbacks[] = $p; }
        }
        $agents = [];
        foreach (is_array($data['fallbackAgentIds'] ?? null) ? $data['fallbackAgentIds'] : [] as $a) {
            if (is_string($a)) { $agents[] = $a; }
        }
        $rsv = [];
        foreach (is_array($data['reservationIds'] ?? null) ? $data['reservationIds'] : [] as $r) {
            if (is_string($r)) { $rsv[] = $r; }
        }
        $trace = [];
        foreach (is_array($data['policyTrace'] ?? null) ? $data['policyTrace'] : [] as $t) {
            if (is_array($t)) { $trace[] = $t; }
        }

        return new self(
            is_string($data['decisionId'] ?? null) ? $data['decisionId'] : self::makeId(),
            ($data['admit'] ?? false) === true,
            is_string($data['mode'] ?? null) ? $data['mode'] : 'balanced',
            is_numeric($data['score'] ?? null) ? (float) $data['score'] : 0.0,
            is_string($data['atUtc'] ?? null) ? $data['atUtc'] : '',
            is_string($data['selectedProviderId'] ?? null) ? $data['selectedProviderId'] : null,
            $fallbacks,
            is_string($data['preferredAgentId'] ?? null) ? $data['preferredAgentId'] : null,
            $agents,
            is_string($data['delayUntilUtc'] ?? null) ? $data['delayUntilUtc'] : null,
            $rsv,
            $trace,
            is_numeric($data['estimatedCost'] ?? null) ? (float) $data['estimatedCost'] : 0.0,
            is_string($data['programId'] ?? null) ? $data['programId'] : null,
            is_string($data['nodeId'] ?? null) ? $data['nodeId'] : null,
            is_string($data['missionId'] ?? null) ? $data['missionId'] : null,
            is_string($data['reason'] ?? null) ? $data['reason'] : '',
            is_array($data['meta'] ?? null) ? $data['meta'] : [],
            is_string($data['fingerprint'] ?? null) ? $data['fingerprint'] : '',
        );
    }

    public static function makeId(): string { return 'opt_' . bin2hex(random_bytes(6)); }
}
