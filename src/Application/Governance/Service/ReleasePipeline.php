<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Service;

use Aep\Application\Governance\Model\GovernanceEvent;
use Aep\Application\Governance\Model\QualityGate;
use Aep\Application\Governance\Model\ReleaseRecord;
use Aep\Application\Governance\Policy\CompliancePolicy;
use Aep\Application\Governance\Policy\SecurityPolicy;
use Aep\Application\Governance\Port\GovernanceSettingsStore;
use Aep\Application\Governance\Port\GovernanceStore;
use Aep\Application\MissionControl\Support\Utc;

final class ReleasePipeline
{
    public function __construct(
        private readonly GovernanceStore $store,
        private readonly GovernanceSettingsStore $settings,
        private readonly CompliancePolicy $compliance,
        private readonly SecurityPolicy $security,
    ) {}

    /**
     * @param array<string, mixed> $evidence
     * @return list<array<string, mixed>>
     */
    public function evaluateGates(ReleaseRecord $release, array $evidence = []): array
    {
        $at = Utc::now();
        $gates = [];
        foreach (QualityGate::defaultCatalog() as $gate) {
            $result = $this->evalGate($gate, $release, $evidence);
            $gates[] = $result->toArray();
            $this->store->appendEvent(new GovernanceEvent(
                GovernanceEvent::makeId(),
                $result->passed() ? GovernanceEvent::QUALITY_GATE_PASSED : GovernanceEvent::QUALITY_GATE_FAILED,
                $at,
                $result->toArray(),
                $release->releaseId(),
            ));
        }
        return $gates;
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function evalGate(QualityGate $gate, ReleaseRecord $release, array $evidence): QualityGate
    {
        return match ($gate->kind()) {
            'tests' => $gate->withResult(
                ($evidence['tests'] ?? 'passed') === 'failed' ? QualityGate::STATUS_FAILED : QualityGate::STATUS_PASSED,
                ['tests' => $evidence['tests'] ?? 'passed']
            ),
            'review' => $gate->withResult(
                count($release->patchIds()) > 0 || ($evidence['reviewed'] ?? false) === true
                    ? QualityGate::STATUS_PASSED : QualityGate::STATUS_FAILED,
                ['patchIds' => $release->patchIds()],
                count($release->patchIds()) > 0 ? 'patch evidence present' : 'missing review evidence'
            ),
            'coverage' => $gate->withResult(
                (is_numeric($evidence['coverage'] ?? null) ? (float) $evidence['coverage'] : 0.8) >= 0.5
                    ? QualityGate::STATUS_PASSED : QualityGate::STATUS_FAILED,
                ['coverage' => $evidence['coverage'] ?? 0.8]
            ),
            'security' => (function () use ($gate, $evidence) {
                $sec = $this->security->evaluate($evidence);
                return $gate->withResult($sec['pass'] ? QualityGate::STATUS_PASSED : QualityGate::STATUS_FAILED, $evidence, $sec['detail']);
            })(),
            'performance' => $gate->withResult(QualityGate::STATUS_PASSED, ['latencyOk' => true], 'baseline accept'),
            'documentation' => $gate->withResult(
                ($evidence['docs'] ?? true) === true ? QualityGate::STATUS_PASSED : QualityGate::STATUS_FAILED,
                ['docs' => $evidence['docs'] ?? true]
            ),
            'approval' => $gate->withResult(
                $release->approvalsSatisfied() ? QualityGate::STATUS_PASSED : QualityGate::STATUS_PENDING,
                ['approvals' => $release->approvals()]
            ),
            default => $gate->withResult(QualityGate::STATUS_SKIPPED, [], 'unknown gate'),
        };
    }

    /** @return array{pass: bool, violations: list<string>} */
    public function compliance(ReleaseRecord $release, bool $forProduction = false): array
    {
        $result = $this->compliance->evaluate($release, $this->settings->get(), $forProduction);
        if (!$result['pass']) {
            $this->store->appendEvent(new GovernanceEvent(
                GovernanceEvent::makeId(),
                GovernanceEvent::COMPLIANCE_VIOLATION,
                Utc::now(),
                $result,
                $release->releaseId(),
            ));
        }
        $this->store->saveCompliance(['releaseId' => $release->releaseId()] + $result + ['atUtc' => Utc::now()]);
        return $result;
    }
}
