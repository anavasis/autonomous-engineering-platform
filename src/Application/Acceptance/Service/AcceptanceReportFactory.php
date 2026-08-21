<?php

declare(strict_types=1);

namespace Aep\Application\Acceptance\Service;

use Aep\Application\Acceptance\Model\AcceptanceContext;
use Aep\Application\Acceptance\Model\AcceptanceProject;
use Aep\Application\Acceptance\Model\AcceptanceReport;
use Aep\Application\Acceptance\Model\ValidationOutcome;
use Aep\Application\MissionControl\Support\Utc;

final class AcceptanceReportFactory
{
    /**
     * @param list<ValidationOutcome> $outcomes
     */
    public function build(
        AcceptanceProject $project,
        AcceptanceContext $context,
        array $outcomes,
    ): AcceptanceReport {
        $passed = [];
        $failed = [];
        foreach ($outcomes as $outcome) {
            if ($outcome->passed()) {
                $passed[] = $outcome;
            } else {
                $failed[] = $outcome;
            }
        }

        $success = $this->meetsSuccessCriteria($project, $outcomes);
        $recommendation = $this->recommend($success, $failed, $context);

        return new AcceptanceReport(
            'arpt_' . bin2hex(random_bytes(8)),
            $project->name(),
            $success,
            [
                'missionId' => $context->missionId(),
                'runId' => $context->runId(),
                'jobId' => $context->jobId(),
                'engineState' => $context->engineState(),
                'runtimeStatus' => $context->runtimeStatus(),
                'projectStarted' => $context->projectStarted(),
                'passedCount' => count($passed),
                'failedCount' => count($failed),
                'rulesTotal' => count($outcomes),
            ],
            $passed,
            $failed,
            $context->artifacts(),
            $context->executionTimeSeconds(),
            $context->providerUsage(),
            $context->runtimeEvents(),
            $recommendation,
            Utc::now(),
        );
    }

    /**
     * @param list<ValidationOutcome> $outcomes
     */
    private function meetsSuccessCriteria(AcceptanceProject $project, array $outcomes): bool
    {
        $criteria = $project->successCriteria();
        $byId = [];
        foreach ($outcomes as $outcome) {
            $byId[$outcome->ruleId()] = $outcome;
        }

        $required = $criteria['requiredRuleIds'] ?? null;
        if (is_array($required) && $required !== []) {
            foreach ($required as $ruleId) {
                if (!is_string($ruleId)) {
                    continue;
                }
                $outcome = $byId[$ruleId] ?? null;
                if ($outcome === null || !$outcome->passed()) {
                    return false;
                }
            }

            return true;
        }

        $requireAll = ($criteria['requireAllRules'] ?? true) === true;
        if ($requireAll) {
            foreach ($outcomes as $outcome) {
                if (!$outcome->passed()) {
                    return false;
                }
            }

            return $outcomes !== [];
        }

        // At least one passed when requireAllRules is false and no required list.
        foreach ($outcomes as $outcome) {
            if ($outcome->passed()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<ValidationOutcome> $failed
     */
    private function recommend(bool $success, array $failed, AcceptanceContext $context): string
    {
        if ($success) {
            return 'Acceptance criteria met. Safe to treat this run as a successful autonomous delivery validation.';
        }
        if (!$context->projectStarted()) {
            return 'Execution never started. Check intake readiness, project binding, and Runtime worker availability.';
        }
        if ($context->runtimeStatus() === 'failed' || $context->engineState() === 'failed') {
            return 'Execution failed. Inspect runtime events, provider logs, and mission timeline before retrying.';
        }
        if ($failed === []) {
            return 'Acceptance did not meet success criteria. Review project successCriteria configuration.';
        }
        $ids = array_map(static fn (ValidationOutcome $o) => $o->ruleId(), array_slice($failed, 0, 5));

        return 'Acceptance incomplete. Failed rules: ' . implode(', ', $ids) . '. Address failures and re-run.';
    }
}
