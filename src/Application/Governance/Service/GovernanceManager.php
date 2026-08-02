<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Service;

use Aep\Application\Governance\Model\ChangeRequest;
use Aep\Application\Governance\Model\DeploymentTarget;
use Aep\Application\Governance\Model\Environment;
use Aep\Application\Governance\Model\GovernanceEvent;
use Aep\Application\Governance\Model\QualityGate;
use Aep\Application\Governance\Port\GovernanceSettingsStore;
use Aep\Application\Governance\Port\GovernanceStore;
use Aep\Application\MissionControl\Support\Utc;

final class GovernanceManager
{
    public function __construct(
        private readonly GovernanceStore $store,
        private readonly GovernanceSettingsStore $settings,
        private readonly AuditManager $audit,
    ) {}

    /** @param array<string, mixed> $config */
    public function seedFromConfig(array $config): void
    {
        foreach (is_array($config['environments'] ?? null) ? $config['environments'] : [] as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null)) { continue; }
            if ($this->store->findEnvironment($row['id']) !== null) { continue; }
            $this->store->saveEnvironment(Environment::fromArray([
                'environmentId' => $row['id'],
                'name' => $row['name'] ?? $row['id'],
                'kind' => $row['kind'] ?? Environment::KIND_CUSTOM,
                'promotionOrder' => $row['promotionOrder'] ?? 0,
            ]));
        }
        foreach (is_array($config['targets'] ?? null) ? $config['targets'] : [] as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null)) { continue; }
            $this->store->saveTarget(DeploymentTarget::fromArray([
                'targetId' => $row['id'],
                'environmentId' => $row['environmentId'] ?? '',
                'label' => $row['label'] ?? $row['id'],
                'serverRef' => $row['serverRef'] ?? null,
                'url' => $row['url'] ?? null,
            ]));
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createChangeRequest(array $input, string $actorId = 'system'): ChangeRequest
    {
        $at = Utc::now();
        $cr = ChangeRequest::fromArray([
            'changeRequestId' => ChangeRequest::makeId(),
            'title' => $input['title'] ?? 'Change Request',
            'status' => ChangeRequest::STATUS_OPEN,
            'createdAtUtc' => $at,
            'updatedAtUtc' => $at,
            'requestedBy' => $actorId,
            'patchIds' => $input['patchIds'] ?? [],
            'missionIds' => $input['missionIds'] ?? [],
            'artifactIds' => $input['artifactIds'] ?? [],
            'programId' => $input['programId'] ?? null,
            'meta' => $input['meta'] ?? [],
        ]);
        $this->store->saveChangeRequest($cr);
        $this->store->appendEvent(new GovernanceEvent(
            GovernanceEvent::makeId(), GovernanceEvent::CHANGE_REQUEST_CREATED, $at, $cr->toArray(), null, $actorId
        ));
        $this->audit->record($actorId, 'change_request.create', 'change_request', $cr->changeRequestId(), null, $cr->toArray());
        return $cr;
    }

    /** @return list<array<string, mixed>> */
    public function qualityGateCatalog(): array
    {
        return array_map(static fn (QualityGate $g) => $g->toArray(), QualityGate::defaultCatalog());
    }

    /** @return array<string, mixed> */
    public function settings(): array { return $this->settings->get(); }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function updateSettings(array $settings): array { return $this->settings->put($settings); }
}
