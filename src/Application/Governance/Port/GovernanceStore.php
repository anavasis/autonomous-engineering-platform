<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Port;

use Aep\Application\Governance\Model\AuditEntry;
use Aep\Application\Governance\Model\ChangeRequest;
use Aep\Application\Governance\Model\DeploymentPlan;
use Aep\Application\Governance\Model\DeploymentTarget;
use Aep\Application\Governance\Model\Environment;
use Aep\Application\Governance\Model\GovernanceEvent;
use Aep\Application\Governance\Model\ReleaseRecord;
use Aep\Application\Governance\Model\RollbackPlan;

interface GovernanceStore
{
    public function saveChangeRequest(ChangeRequest $cr): void;
    public function findChangeRequest(string $id): ?ChangeRequest;
    /** @return list<ChangeRequest> */
    public function listChangeRequests(?string $status = null): array;

    public function saveRelease(ReleaseRecord $release): void;
    public function findRelease(string $id): ?ReleaseRecord;
    /** @return list<ReleaseRecord> */
    public function listReleases(?string $status = null): array;
    public function findReleaseByPatch(string $patchId): ?ReleaseRecord;

    public function saveEnvironment(Environment $env): void;
    /** @return list<Environment> */
    public function listEnvironments(): array;
    public function findEnvironment(string $id): ?Environment;

    public function saveTarget(DeploymentTarget $target): void;
    /** @return list<DeploymentTarget> */
    public function listTargets(?string $environmentId = null): array;

    public function saveDeployment(DeploymentPlan $plan): void;
    public function findDeployment(string $id): ?DeploymentPlan;
    /** @return list<DeploymentPlan> */
    public function listDeployments(?string $releaseId = null): array;

    public function saveRollback(RollbackPlan $plan): void;
    public function findRollback(string $id): ?RollbackPlan;
    /** @return list<RollbackPlan> */
    public function listRollbacks(): array;

    public function appendEvent(GovernanceEvent $event): void;
    /** @return list<GovernanceEvent> */
    public function events(int $limit = 200): array;

    public function appendAudit(AuditEntry $entry): void;
    /** @return list<AuditEntry> */
    public function audit(int $limit = 200): array;

    /** @param array<string, mixed> $metrics */
    public function saveMetrics(array $metrics): void;
    /** @return array<string, mixed> */
    public function metrics(): array;

    /** @param array<string, mixed> $compliance */
    public function saveCompliance(array $compliance): void;
    /** @return array<string, mixed> */
    public function compliance(): array;
}
