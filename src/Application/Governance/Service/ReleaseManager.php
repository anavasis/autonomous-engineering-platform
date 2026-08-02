<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Service;

use Aep\Application\Governance\Model\DeploymentPlan;
use Aep\Application\Governance\Model\GovernanceEvent;
use Aep\Application\Governance\Model\ReleaseCandidate;
use Aep\Application\Governance\Model\ReleaseRecord;
use Aep\Application\Governance\Model\RollbackPlan;
use Aep\Application\Governance\Policy\ApprovalPolicy;
use Aep\Application\Governance\Policy\PromotionPolicy;
use Aep\Application\Governance\Policy\RollbackPolicy;
use Aep\Application\Governance\Port\GovernanceSettingsStore;
use Aep\Application\Governance\Port\GovernanceStore;
use Aep\Application\MissionControl\Support\Utc;

final class ReleaseManager
{
    public function __construct(
        private readonly GovernanceStore $store,
        private readonly GovernanceSettingsStore $settings,
        private readonly ReleasePipeline $pipeline,
        private readonly AuditManager $audit,
        private readonly ApprovalPolicy $approvals = new ApprovalPolicy(),
        private readonly PromotionPolicy $promotion = new PromotionPolicy(),
        private readonly RollbackPolicy $rollbackPolicy = new RollbackPolicy(),
    ) {}

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, string $actorId = 'system'): ReleaseRecord
    {
        $at = Utc::now();
        $stages = ApprovalPolicy::stagesFromSettings($this->settings->get());
        $release = new ReleaseRecord(
            ReleaseRecord::makeId(),
            is_string($input['version'] ?? null) ? $input['version'] : ('1.0.0-rc.' . substr(bin2hex(random_bytes(2)), 0, 4)),
            ReleaseRecord::STATUS_DRAFT,
            $at,
            $at,
            is_string($input['title'] ?? null) ? $input['title'] : 'Release',
            $this->strList($input['changeRequestIds'] ?? []),
            $this->strList($input['patchIds'] ?? []),
            $this->strList($input['missionIds'] ?? []),
            $this->strList($input['artifactIds'] ?? []),
            [],
            $stages,
            null,
            null,
            '',
            is_array($input['meta'] ?? null) ? $input['meta'] : [],
        );
        $release = $release->withSealedHash();
        $this->store->saveRelease($release);
        $this->store->appendEvent(new GovernanceEvent(
            GovernanceEvent::makeId(), GovernanceEvent::RELEASE_CREATED, $at, $release->toArray(), $release->releaseId(), $actorId
        ));
        $this->audit->record($actorId, 'release.create', 'release', $release->releaseId(), null, $release->toArray());
        $this->touchMetrics('releasesCreated');
        return $release;
    }

    /**
     * @param array<string, mixed> $evidence
     */
    public function submitCandidate(string $releaseId, array $evidence = [], string $actorId = 'system'): ReleaseCandidate
    {
        $release = $this->require($releaseId);
        $at = Utc::now();
        $gates = $this->pipeline->evaluateGates($release, $evidence);
        $release = $release->withGates($gates, $at)->withStatus(ReleaseRecord::STATUS_CANDIDATE, $at)->withSealedHash();
        $this->store->saveRelease($release);
        $this->audit->record($actorId, 'release.submit', 'release', $releaseId, null, $release->toArray());
        return new ReleaseCandidate($release);
    }

    public function approve(string $releaseId, string $stageId, string $actorId): ReleaseRecord
    {
        $release = $this->require($releaseId);
        $at = Utc::now();
        $approvals = $this->approvals->grant($release->approvals(), $stageId, $actorId, $at);
        $release = $release->withApprovals($approvals, $at);
        // re-eval approval gate
        $gates = $this->pipeline->evaluateGates($release, ['reviewed' => true, 'tests' => 'passed']);
        $release = $release->withGates($gates, $at);
        if ($this->approvals->satisfied($approvals) && $release->allGatesPassed()) {
            $release = $release->withStatus(ReleaseRecord::STATUS_APPROVED, $at);
            $this->store->appendEvent(new GovernanceEvent(
                GovernanceEvent::makeId(), GovernanceEvent::RELEASE_APPROVED, $at, ['stageId' => $stageId], $releaseId, $actorId
            ));
        }
        $this->store->appendEvent(new GovernanceEvent(
            GovernanceEvent::makeId(), GovernanceEvent::APPROVAL_GRANTED, $at, ['stageId' => $stageId], $releaseId, $actorId
        ));
        $release = $release->withSealedHash();
        $this->store->saveRelease($release);
        $this->audit->record($actorId, 'release.approve', 'release', $releaseId, null, ['stageId' => $stageId]);
        return $release;
    }

    public function reject(string $releaseId, string $stageId, string $actorId): ReleaseRecord
    {
        $release = $this->require($releaseId);
        $at = Utc::now();
        $approvals = $this->approvals->reject($release->approvals(), $stageId, $actorId, $at);
        $release = $release->withApprovals($approvals, $at)->withStatus(ReleaseRecord::STATUS_REJECTED, $at)->withSealedHash();
        $this->store->saveRelease($release);
        $this->store->appendEvent(new GovernanceEvent(
            GovernanceEvent::makeId(), GovernanceEvent::APPROVAL_REJECTED, $at, ['stageId' => $stageId], $releaseId, $actorId
        ));
        $this->store->appendEvent(new GovernanceEvent(
            GovernanceEvent::makeId(), GovernanceEvent::RELEASE_REJECTED, $at, [], $releaseId, $actorId
        ));
        $this->audit->record($actorId, 'release.reject', 'release', $releaseId);
        return $release;
    }

    public function schedule(string $releaseId, string $scheduledAtUtc, string $actorId = 'system'): ReleaseRecord
    {
        $release = $this->require($releaseId);
        if ($release->status() !== ReleaseRecord::STATUS_APPROVED) {
            throw new \InvalidArgumentException('Only approved releases can be scheduled.');
        }
        $at = Utc::now();
        $release = $release->withSchedule($scheduledAtUtc, $at)->withStatus(ReleaseRecord::STATUS_SCHEDULED, $at)->withSealedHash();
        $this->store->saveRelease($release);
        $this->audit->record($actorId, 'release.schedule', 'release', $releaseId, null, ['scheduledAtUtc' => $scheduledAtUtc]);
        return $release;
    }

    public function promote(string $releaseId, string $environmentId, string $actorId = 'system'): DeploymentPlan
    {
        $release = $this->require($releaseId);
        $env = $this->store->findEnvironment($environmentId);
        if ($env === null) {
            throw new \InvalidArgumentException('Unknown environment.');
        }
        $from = $release->currentEnvironmentId() ? $this->store->findEnvironment($release->currentEnvironmentId()) : null;
        $check = $this->promotion->canPromote($from, $env, $this->store->listEnvironments());
        if (!$check['admit']) {
            throw new \RuntimeException($check['reason']);
        }
        $compliance = $this->pipeline->compliance($release, $env->isProduction());
        if (!$compliance['pass'] && ($this->settings->get()['hardComplianceGate'] ?? true) === true) {
            throw new \RuntimeException('Compliance blocked: ' . implode(', ', $compliance['violations']));
        }
        return $this->deploy($releaseId, $environmentId, $actorId);
    }

    public function deploy(string $releaseId, string $environmentId, string $actorId = 'system'): DeploymentPlan
    {
        $release = $this->require($releaseId);
        $at = Utc::now();
        $plan = new DeploymentPlan(
            DeploymentPlan::makeId(), $releaseId, $environmentId, DeploymentPlan::STATUS_STARTED, $at, $at,
            null, [['step' => 'record', 'status' => 'started']], ['actorId' => $actorId], 'deployment started'
        );
        $this->store->saveDeployment($plan);
        $this->store->appendEvent(new GovernanceEvent(
            GovernanceEvent::makeId(), GovernanceEvent::DEPLOYMENT_STARTED, $at, $plan->toArray(), $releaseId, $actorId
        ));
        $release = $release->withStatus(ReleaseRecord::STATUS_DEPLOYING, $at)->withEnvironment($environmentId, $at);
        $this->store->saveRelease($release);

        // Record-only deploy finish (no host mutation; preserves EE/Workspace contracts)
        $plan = $plan->withStatus(DeploymentPlan::STATUS_FINISHED, Utc::now(), 'recorded deployment');
        $this->store->saveDeployment($plan);
        $release = $release->withStatus(ReleaseRecord::STATUS_DEPLOYED, Utc::now())->withSealedHash();
        $this->store->saveRelease($release);
        $this->store->appendEvent(new GovernanceEvent(
            GovernanceEvent::makeId(), GovernanceEvent::DEPLOYMENT_FINISHED, Utc::now(), $plan->toArray(), $releaseId, $actorId
        ));
        $this->audit->record($actorId, 'release.deploy', 'deployment', $plan->deploymentId(), null, $plan->toArray(), [], ['releaseId' => $releaseId]);
        $this->touchMetrics('deploymentsFinished');
        return $plan;
    }

    public function archive(string $releaseId, string $actorId = 'system'): ReleaseRecord
    {
        $release = $this->require($releaseId)->withStatus(ReleaseRecord::STATUS_ARCHIVED, Utc::now())->withSealedHash();
        $this->store->saveRelease($release);
        $this->audit->record($actorId, 'release.archive', 'release', $releaseId);
        return $release;
    }

    public function planRollback(string $releaseId, string $toReleaseId, string $environmentId, string $actorId = 'system'): RollbackPlan
    {
        $current = $this->require($releaseId);
        $prior = $this->store->findRelease($toReleaseId);
        $history = $this->store->listDeployments($releaseId);
        $check = $this->rollbackPolicy->canRollback($current, $prior, $history);
        if (!$check['admit']) {
            throw new \RuntimeException($check['reason']);
        }
        $at = Utc::now();
        $plan = new RollbackPlan(
            RollbackPlan::makeId(), $releaseId, $toReleaseId, $environmentId, RollbackPlan::STATUS_PLANNED, $at, $at,
            ['verify prior release', 'switch traffic', 'validate health'], ['actorId' => $actorId]
        );
        $this->store->saveRollback($plan);
        $this->audit->record($actorId, 'rollback.plan', 'rollback', $plan->rollbackId(), null, $plan->toArray());
        return $plan;
    }

    public function startRollback(string $rollbackId, string $actorId = 'system'): RollbackPlan
    {
        $plan = $this->store->findRollback($rollbackId);
        if ($plan === null) { throw new \InvalidArgumentException('Unknown rollback.'); }
        $at = Utc::now();
        $plan = $plan->withStatus(RollbackPlan::STATUS_STARTED, $at);
        $this->store->saveRollback($plan);
        $this->store->appendEvent(new GovernanceEvent(
            GovernanceEvent::makeId(), GovernanceEvent::ROLLBACK_STARTED, $at, $plan->toArray(), $plan->releaseId(), $actorId
        ));
        return $plan;
    }

    public function completeRollback(string $rollbackId, string $actorId = 'system'): RollbackPlan
    {
        $plan = $this->store->findRollback($rollbackId);
        if ($plan === null) { throw new \InvalidArgumentException('Unknown rollback.'); }
        $at = Utc::now();
        $plan = $plan->withStatus(RollbackPlan::STATUS_COMPLETED, $at, 'rollback recorded');
        $this->store->saveRollback($plan);
        $release = $this->require($plan->releaseId())->withStatus(ReleaseRecord::STATUS_ROLLED_BACK, $at)->withSealedHash();
        $this->store->saveRelease($release);
        $this->store->appendEvent(new GovernanceEvent(
            GovernanceEvent::makeId(), GovernanceEvent::ROLLBACK_COMPLETED, $at, $plan->toArray(), $plan->releaseId(), $actorId
        ));
        $this->audit->record($actorId, 'rollback.complete', 'rollback', $rollbackId);
        $this->touchMetrics('rollbacksCompleted');
        return $plan;
    }

    private function require(string $id): ReleaseRecord
    {
        $r = $this->store->findRelease($id);
        if ($r === null) { throw new \InvalidArgumentException('Unknown release: ' . $id); }
        return $r;
    }

    /** @return list<string> */
    private function strList(mixed $v): array
    {
        $out = [];
        if (!is_array($v)) { return $out; }
        foreach ($v as $i) { if (is_string($i)) { $out[] = $i; } }
        return $out;
    }

    private function touchMetrics(string $key): void
    {
        $m = $this->store->metrics();
        $m[$key] = (int) ($m[$key] ?? 0) + 1;
        $m['updatedAtUtc'] = Utc::now();
        $this->store->saveMetrics($m);
    }
}
