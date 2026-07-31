<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Port;

use Aep\Application\EngineeringExecution\Model\ProviderCapabilities;
use Aep\Application\EngineeringExecution\Model\ProviderEvent;
use Aep\Application\EngineeringExecution\Model\ProviderHealth;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Model\ProviderSessionRequest;

/**
 * Pluggable engineering execution provider contract.
 * Mission Engine never depends on this port directly.
 */
interface EngineeringExecutionProvider
{
    public function id(): string;

    public function displayName(): string;

    public function capabilities(): ProviderCapabilities;

    public function health(): ProviderHealth;

    /**
     * Start (or resume) a provider session. Implementations may run synchronously
     * and buffer events for poll().
     */
    public function start(ProviderSessionRequest $request): void;

    public function cancel(string $sessionId, string $reason = 'Cancelled.'): void;

    /**
     * @param array<string, mixed> $checkpoint
     */
    public function resume(string $sessionId, array $checkpoint): void;

    /**
     * @return list<ProviderEvent>
     */
    public function poll(string $sessionId, int $afterSeq = 0): array;

    public function collectResult(string $sessionId): ProviderResult;
}
