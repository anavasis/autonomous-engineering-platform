<?php

declare(strict_types=1);

namespace Tests\Support;

use Aep\Domain\Mission\Mission;
use Aep\Domain\Mission\ValueObject\ActorRef;
use Aep\Domain\Mission\ValueObject\ApprovalRecord;
use Aep\Domain\Mission\ValueObject\InspectionFindings;
use Aep\Domain\Mission\ValueObject\MissionBrief;
use Aep\Domain\Mission\ValueObject\MissionId;
use Aep\Domain\Mission\ValueObject\ScopePolicy;
use Aep\Domain\Mission\ValueObject\TargetRepositoryRef;
use Aep\Domain\Mission\ValueObject\ValidationResult;

/**
 * Shared deterministic fixtures for ORCH-R3 tests.
 */
final class MissionFixtures
{
    public const AT = '2026-07-30T12:00:00Z';

    public static function actor(string $id = 'tester-1'): ActorRef
    {
        return new ActorRef('user', $id);
    }

    public static function newDraft(string $missionId = 'msn_test_1'): Mission
    {
        return Mission::create(
            new MissionId($missionId),
            new TargetRepositoryRef('github', 'anavasis/example-target'),
            new MissionBrief('ORCH-R3 verification mission'),
            self::actor(),
            self::AT
        );
    }

    public static function scope(): ScopePolicy
    {
        return new ScopePolicy(
            ['src/Domain/Mission/Mission.php'],
            ['executors', 'wordpress']
        );
    }

    public static function findings(string $summary = 'Inspection ready'): InspectionFindings
    {
        return new InspectionFindings($summary, 'ready');
    }

    public static function inspectionApproval(bool $approved = true): ApprovalRecord
    {
        return new ApprovalRecord(
            ApprovalRecord::SUBJECT_INSPECTION,
            $approved ? ApprovalRecord::DECISION_APPROVED : ApprovalRecord::DECISION_REJECTED,
            self::actor(),
            self::AT,
            $approved ? 'approved' : 'revise'
        );
    }

    public static function commitApproval(bool $approved = true): ApprovalRecord
    {
        return new ApprovalRecord(
            ApprovalRecord::SUBJECT_COMMIT,
            $approved ? ApprovalRecord::DECISION_APPROVED : ApprovalRecord::DECISION_REJECTED,
            self::actor(),
            self::AT,
            $approved ? 'approved' : 'revise'
        );
    }

    /**
     * Advance a fresh draft through inspection approval into implementing.
     */
    public static function toImplementing(string $missionId = 'msn_test_1'): Mission
    {
        $mission = self::newDraft($missionId);
        $mission->defineScope(self::scope());
        $mission->startInspection(self::AT);
        $mission->submitInspection(self::findings(), self::AT);
        $mission->approveInspection(self::inspectionApproval(true), self::AT);
        $mission->pullRecordedEvents();

        return $mission;
    }

    /**
     * Advance to validating (post-implementation).
     */
    public static function toValidating(string $missionId = 'msn_test_1'): Mission
    {
        $mission = self::toImplementing($missionId);
        $mission->finishImplementation(self::AT);
        $mission->pullRecordedEvents();

        return $mission;
    }

    /**
     * Advance to awaiting commit approval after passed validation.
     */
    public static function toAwaitingCommitApproval(string $missionId = 'msn_test_1'): Mission
    {
        $mission = self::toValidating($missionId);
        $mission->recordValidation(ValidationResult::passed('ok'), self::AT);
        $mission->pullRecordedEvents();

        return $mission;
    }

    /**
     * Advance to pr_ready.
     */
    public static function toPrReady(string $missionId = 'msn_test_1'): Mission
    {
        $mission = self::toAwaitingCommitApproval($missionId);
        $mission->approveCommit(self::commitApproval(true), self::AT);
        $mission->markPrReady(self::AT);
        $mission->pullRecordedEvents();

        return $mission;
    }

    /**
     * @return list<string>
     */
    public static function eventNames(Mission $mission): array
    {
        $names = [];
        foreach ($mission->recordedEvents() as $event) {
            $names[] = $event->eventName();
        }

        return $names;
    }
}
