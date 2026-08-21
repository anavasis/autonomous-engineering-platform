<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Service;

use Aep\Application\Governance\Model\ReleaseRecord;
use Aep\Application\Governance\Port\GovernanceSettingsStore;
use Aep\Application\Governance\Port\GovernanceStore;

final class GovernanceQueryService
{
    public function __construct(
        private readonly GovernanceStore $store,
        private readonly GovernanceSettingsStore $settings,
        private readonly GovernanceManager $manager,
        private readonly ReleaseManager $releases,
        private readonly ReleasePipeline $pipeline,
        private readonly AuditManager $audit,
        private readonly GovernanceObserveFacade $observeFacade,
    ) {}

    /** @return array<string, mixed> */
    public function settings(): array { return $this->settings->get(); }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function updateSettings(array $settings): array { return $this->manager->updateSettings($settings); }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $releases = $this->store->listReleases();
        $byStatus = [];
        foreach ($releases as $r) {
            $byStatus[$r->status()] = ($byStatus[$r->status()] ?? 0) + 1;
        }
        $openCr = count($this->store->listChangeRequests('open'));
        $compliance = $this->store->compliance();
        $waitingApprovals = 0;
        foreach ($releases as $r) {
            if (in_array($r->status(), [ReleaseRecord::STATUS_CANDIDATE, ReleaseRecord::STATUS_DRAFT], true) && !$r->approvalsSatisfied()) {
                $waitingApprovals++;
            }
        }
        return [
            'enabled' => ($this->settings->get()['enabled'] ?? true) === true,
            'engineeringGovernance' => ($this->settings->get()['engineeringGovernance'] ?? true) === true,
            'releaseCount' => count($releases),
            'byStatus' => $byStatus,
            'openChangeRequests' => $openCr,
            'waitingApprovals' => $waitingApprovals,
            'deployments' => count($this->store->listDeployments()),
            'rollbacks' => count($this->store->listRollbacks()),
            'compliance' => $compliance,
            'metrics' => $this->store->metrics(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function changeRequests(?string $status = null): array
    {
        return array_map(static fn ($c) => $c->toArray(), $this->store->listChangeRequests($status));
    }

    /** @return array<string, mixed>|null */
    public function changeRequest(string $id): ?array
    {
        return $this->store->findChangeRequest($id)?->toArray();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createChangeRequest(array $input, string $actorId): array
    {
        return $this->manager->createChangeRequest($input, $actorId)->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function releases(?string $status = null): array
    {
        return array_map(static fn ($r) => $r->toArray(), $this->store->listReleases($status));
    }

    /** @return array<string, mixed>|null */
    public function release(string $id): ?array
    {
        return $this->store->findRelease($id)?->toArray();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createRelease(array $input, string $actorId): array
    {
        return $this->releases->create($input, $actorId)->toArray();
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    public function submitRelease(string $id, array $evidence, string $actorId): array
    {
        return $this->releases->submitCandidate($id, $evidence, $actorId)->toArray();
    }

    /** @return array<string, mixed> */
    public function approveRelease(string $id, string $stageId, string $actorId): array
    {
        return $this->releases->approve($id, $stageId, $actorId)->toArray();
    }

    /** @return array<string, mixed> */
    public function rejectRelease(string $id, string $stageId, string $actorId): array
    {
        return $this->releases->reject($id, $stageId, $actorId)->toArray();
    }

    /** @return array<string, mixed> */
    public function scheduleRelease(string $id, string $scheduledAtUtc, string $actorId): array
    {
        return $this->releases->schedule($id, $scheduledAtUtc, $actorId)->toArray();
    }

    /** @return array<string, mixed> */
    public function promoteRelease(string $id, string $environmentId, string $actorId): array
    {
        return $this->releases->promote($id, $environmentId, $actorId)->toArray();
    }

    /** @return array<string, mixed> */
    public function deployRelease(string $id, string $environmentId, string $actorId): array
    {
        return $this->releases->deploy($id, $environmentId, $actorId)->toArray();
    }

    /** @return array<string, mixed> */
    public function archiveRelease(string $id, string $actorId): array
    {
        return $this->releases->archive($id, $actorId)->toArray();
    }

    /**
     * @param array<string, mixed> $evidence
     * @return list<array<string, mixed>>
     */
    public function evaluateGates(string $id, array $evidence = []): array
    {
        $release = $this->store->findRelease($id);
        if ($release === null) { return []; }
        $gates = $this->pipeline->evaluateGates($release, $evidence);
        $updated = $release->withGates($gates, $release->toArray()['updatedAtUtc'] ?? '')->withSealedHash();
        $this->store->saveRelease($updated);
        return $gates;
    }

    /** @return list<array<string, mixed>> */
    public function governanceApprovals(): array
    {
        $items = [];
        foreach ($this->store->listReleases() as $r) {
            if ($r->approvalsSatisfied()) { continue; }
            if (!in_array($r->status(), [ReleaseRecord::STATUS_CANDIDATE, ReleaseRecord::STATUS_DRAFT, ReleaseRecord::STATUS_APPROVED], true)) {
                continue;
            }
            foreach ($r->approvals() as $stage) {
                if (($stage['status'] ?? '') === 'granted') { continue; }
                $items[] = [
                    'releaseId' => $r->releaseId(),
                    'version' => $r->version(),
                    'title' => $r->title(),
                    'stageId' => $stage['stageId'] ?? '',
                    'stageName' => $stage['name'] ?? '',
                    'status' => $stage['status'] ?? 'pending',
                    'minApprovals' => $stage['minApprovals'] ?? 1,
                    'grants' => $stage['grants'] ?? [],
                ];
            }
        }
        return $items;
    }

    /** @return list<array<string, mixed>> */
    public function deployments(?string $releaseId = null): array
    {
        return array_map(static fn ($d) => $d->toArray(), $this->store->listDeployments($releaseId));
    }

    /** @return array<string, mixed>|null */
    public function deployment(string $id): ?array
    {
        return $this->store->findDeployment($id)?->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function environments(): array
    {
        return array_map(static fn ($e) => $e->toArray(), $this->store->listEnvironments());
    }

    /** @return list<array<string, mixed>> */
    public function rollbacks(): array
    {
        return array_map(static fn ($r) => $r->toArray(), $this->store->listRollbacks());
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createRollback(array $input, string $actorId): array
    {
        return $this->releases->planRollback(
            (string) ($input['releaseId'] ?? ''),
            (string) ($input['toReleaseId'] ?? ''),
            (string) ($input['environmentId'] ?? ''),
            $actorId,
        )->toArray();
    }

    /** @return array<string, mixed> */
    public function startRollback(string $id, string $actorId): array
    {
        return $this->releases->startRollback($id, $actorId)->toArray();
    }

    /** @return array<string, mixed> */
    public function completeRollback(string $id, string $actorId): array
    {
        return $this->releases->completeRollback($id, $actorId)->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function audit(int $limit = 200): array { return $this->audit->trail($limit); }

    /** @return array<string, mixed> */
    public function compliance(): array { return $this->store->compliance(); }

    /** @return list<array<string, mixed>> */
    public function qualityGates(): array { return $this->manager->qualityGateCatalog(); }

    /** @return list<array<string, mixed>> */
    public function timeline(int $limit = 100): array
    {
        return array_map(static fn ($e) => $e->toArray(), $this->store->events($limit));
    }

    /** @return array<string, mixed> */
    public function metrics(): array { return $this->store->metrics(); }

    public function releasesManager(): ReleaseManager { return $this->releases; }
    public function observe(): GovernanceObserveFacade { return $this->observeFacade; }
}
