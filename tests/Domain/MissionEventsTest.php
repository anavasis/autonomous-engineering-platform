<?php

declare(strict_types=1);

namespace Tests\Domain;

use Aep\Domain\Mission\ValueObject\ValidationResult;
use Tests\Support\Assert;
use Tests\Support\MissionFixtures;

/**
 * Domain event recording and pullRecordedEvents semantics.
 */
final class MissionEventsTest
{
    public function test_create_records_mission_created(): void
    {
        $mission = MissionFixtures::newDraft('msn_ev_create');
        Assert::eventNames(['MissionCreated'], $mission->recordedEvents());
    }

    public function test_happy_path_event_sequence_names(): void
    {
        $mission = MissionFixtures::newDraft('msn_ev_happy');
        $mission->pullRecordedEvents();

        $mission->defineScope(MissionFixtures::scope());
        $mission->startInspection(MissionFixtures::AT);
        $mission->submitInspection(MissionFixtures::findings(), MissionFixtures::AT);
        $mission->approveInspection(MissionFixtures::inspectionApproval(true), MissionFixtures::AT);
        $mission->finishImplementation(MissionFixtures::AT);
        $mission->recordValidation(ValidationResult::passed('ok'), MissionFixtures::AT);
        $mission->approveCommit(MissionFixtures::commitApproval(true), MissionFixtures::AT);
        $mission->markPrReady(MissionFixtures::AT);
        $mission->complete(MissionFixtures::AT);

        Assert::eventNames([
            'InspectionStarted',
            'InspectionSubmitted',
            'InspectionApprovalGranted',
            'ImplementationStarted',
            'ImplementationFinished',
            'ValidationStarted',
            'ValidationPassed',
            'CommitApprovalGranted',
            'CommitPhaseStarted',
            'PullRequestMarkedReady',
            'MissionCompleted',
        ], $mission->recordedEvents());
    }

    public function test_validation_failure_events(): void
    {
        $mission = MissionFixtures::toValidating('msn_ev_fail');
        $mission->recordValidation(ValidationResult::failed('broken'), MissionFixtures::AT);
        Assert::eventNames([
            'ValidationFailed',
            'CorrectionLoopEntered',
        ], $mission->recordedEvents());
    }

    public function test_reject_inspection_event(): void
    {
        $mission = MissionFixtures::newDraft('msn_ev_rej');
        $mission->defineScope(MissionFixtures::scope());
        $mission->startInspection(MissionFixtures::AT);
        $mission->submitInspection(MissionFixtures::findings(), MissionFixtures::AT);
        $mission->pullRecordedEvents();
        $mission->rejectInspection(MissionFixtures::inspectionApproval(false), MissionFixtures::AT);
        Assert::eventNames(['InspectionApprovalRejected'], $mission->recordedEvents());
    }

    public function test_pull_recorded_events_clears_buffer(): void
    {
        $mission = MissionFixtures::newDraft('msn_ev_pull');
        Assert::true($mission->recordedEvents() !== []);
        $first = $mission->pullRecordedEvents();
        Assert::eventNames(['MissionCreated'], $first);
        Assert::same([], $mission->recordedEvents());
        Assert::same([], $mission->pullRecordedEvents());
    }

    public function test_events_accumulate_until_pulled(): void
    {
        $mission = MissionFixtures::newDraft('msn_ev_acc');
        $mission->pullRecordedEvents();
        $mission->startInspection(MissionFixtures::AT);
        Assert::same(['InspectionStarted'], MissionFixtures::eventNames($mission));
        $mission->defineScope(MissionFixtures::scope());
        $mission->submitInspection(MissionFixtures::findings(), MissionFixtures::AT);
        Assert::contains('InspectionSubmitted', MissionFixtures::eventNames($mission));
        Assert::contains('InspectionStarted', MissionFixtures::eventNames($mission));
    }
}
