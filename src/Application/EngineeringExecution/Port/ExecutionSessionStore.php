<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Port;

use Aep\Application\EngineeringExecution\Model\ExecutionSession;
use Aep\Application\EngineeringExecution\Model\ProviderEvent;

interface ExecutionSessionStore
{
    public function save(ExecutionSession $session): void;

    public function find(string $sessionId): ?ExecutionSession;

    public function findLatestForMission(string $missionId): ?ExecutionSession;

    public function appendEvent(string $sessionId, ProviderEvent $event): void;

    /**
     * @return list<ProviderEvent>
     */
    public function events(string $sessionId, int $afterSeq = 0): array;

    /** @param array<string, mixed> $entry */
    public function appendAudit(array $entry): void;
}
