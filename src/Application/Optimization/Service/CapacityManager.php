<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Service;

use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\Optimization\Model\AgentCapacity;
use Aep\Application\Optimization\Model\OptimizationEvent;
use Aep\Application\Optimization\Model\ProviderCapacity;
use Aep\Application\Optimization\Model\ResourceAllocation;
use Aep\Application\Optimization\Model\WorkspaceCapacity;
use Aep\Application\Optimization\Port\OptimizationSettingsStore;
use Aep\Application\Optimization\Port\OptimizationStore;

final class CapacityManager
{
    public function __construct(
        private readonly OptimizationStore $store,
        private readonly OptimizationSettingsStore $settings,
    ) {}

    /** @param array<string, mixed> $config */
    public function seedFromConfig(array $config): void
    {
        foreach (is_array($config['providers'] ?? null) ? $config['providers'] : [] as $row) {
            if (!is_array($row)) { continue; }
            $id = is_string($row['id'] ?? null) ? $row['id'] : '';
            if ($id === '' || $this->store->findProviderCapacity($id) !== null) { continue; }
            $this->store->saveProviderCapacity(ProviderCapacity::fromArray([
                'providerId' => $id,
                'maxSessions' => $row['maxSessions'] ?? 4,
                'successRate' => $row['successRate'] ?? 0.8,
                'avgLatencyMs' => $row['avgLatencyMs'] ?? 1000,
                'failureRate' => $row['failureRate'] ?? 0.1,
                'confidence' => $row['confidence'] ?? 0.75,
            ]));
        }
        foreach (is_array($config['agents'] ?? null) ? $config['agents'] : [] as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null)) { continue; }
            $this->store->saveAgentCapacity(AgentCapacity::fromArray([
                'agentId' => $row['id'],
                'role' => $row['role'] ?? 'implementer',
                'maxConcurrency' => $row['maxConcurrency'] ?? 2,
                'costWeight' => $row['costWeight'] ?? 1.0,
                'confidence' => $row['confidence'] ?? 0.75,
            ]));
        }
        $ws = is_array($config['workspace'] ?? null) ? $config['workspace'] : [];
        $this->store->saveWorkspaceCapacity(WorkspaceCapacity::fromArray($ws));
    }

    public function reserveProvider(string $providerId, string $ownerId, string $ownerType = 'mission'): ?ResourceAllocation
    {
        $cap = $this->store->findProviderCapacity($providerId);
        if ($cap === null || !$cap->isRoutable()) {
            $allow = ($this->settings->get()['allowOvercommit'] ?? false) === true;
            if (!$allow || $cap === null) {
                return null;
            }
            $this->store->appendEvent(new OptimizationEvent(
                OptimizationEvent::makeId(),
                OptimizationEvent::CAPACITY_OVERCOMMITTED,
                Utc::now(),
                ['providerId' => $providerId],
                null,
                $providerId,
            ));
        }
        $at = Utc::now();
        if ($cap !== null) {
            $this->store->saveProviderCapacity($cap->withReserve(1));
        }
        $allocation = new ResourceAllocation(
            ResourceAllocation::makeId(),
            'provider.slot:' . $providerId,
            1.0,
            ResourceAllocation::STATUS_RESERVED,
            $at,
            $at,
            $ownerType,
            $ownerId,
            null,
            ['providerId' => $providerId],
        );
        $this->store->saveAllocation($allocation);
        $this->store->appendEvent(new OptimizationEvent(
            OptimizationEvent::makeId(),
            OptimizationEvent::CAPACITY_RESERVED,
            $at,
            $allocation->toArray(),
            null,
            $providerId,
        ));

        return $allocation;
    }

    public function release(string $reservationId): ?ResourceAllocation
    {
        $allocation = $this->store->findAllocation($reservationId);
        if ($allocation === null) {
            return null;
        }
        $at = Utc::now();
        $allocation = $allocation->withStatus(ResourceAllocation::STATUS_RELEASED, $at);
        $this->store->saveAllocation($allocation);
        $providerId = is_string($allocation->toArray()['meta']['providerId'] ?? null)
            ? $allocation->toArray()['meta']['providerId'] : null;
        if (is_string($providerId)) {
            $cap = $this->store->findProviderCapacity($providerId);
            if ($cap !== null) {
                $this->store->saveProviderCapacity($cap->withRelease(1));
            }
        }
        $this->store->appendEvent(new OptimizationEvent(
            OptimizationEvent::makeId(),
            OptimizationEvent::CAPACITY_RELEASED,
            $at,
            ['reservationId' => $reservationId],
            null,
            $providerId,
        ));

        return $allocation;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'providers' => array_map(static fn (ProviderCapacity $c) => $c->toArray(), $this->store->listProviderCapacity()),
            'agents' => array_map(static fn (AgentCapacity $c) => $c->toArray(), $this->store->listAgentCapacity()),
            'workspaces' => $this->store->workspaceCapacity()->toArray(),
            'reservations' => array_map(
                static fn (ResourceAllocation $a) => $a->toArray(),
                $this->store->listAllocations(ResourceAllocation::STATUS_RESERVED)
            ),
        ];
    }
}
