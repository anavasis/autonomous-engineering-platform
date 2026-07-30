<?php

declare(strict_types=1);

namespace Tests\Domain;

use Aep\Domain\Mission\ValueObject\MissionState;
use Aep\Domain\Mission\ValueObject\ValidationResult;
use Tests\Support\Assert;
use Tests\Support\MissionFixtures;

/**
 * Legal Mission lifecycle / transition matrix coverage.
 */
final class MissionLifecycleTest
{
    public function test_create_enters_draft(): void
    {
        $mission = MissionFixtures::newDraft();
        Assert::same(MissionState::DRAFT, $mission->state()->toString());
    }

    public function test_happy_path_to_completed(): void
    {
        $mission = MissionFixtures::newDraft('msn_happy');
        $mission->defineScope(MissionFixtures::scope());
        $mission->startInspection(MissionFixtures::AT);
        Assert::same(MissionState::INSPECTING, $mission->state()->toString());

        $mission->submitInspection(MissionFixtures::findings(), MissionFixtures::AT);
        Assert::same(MissionState::AWAITING_INSPECTION_APPROVAL, $mission->state()->toString());

        $mission->approveInspection(MissionFixtures::inspectionApproval(true), MissionFixtures::AT);
        Assert::same(MissionState::IMPLEMENTING, $mission->state()->toString());

        $mission->finishImplementation(MissionFixtures::AT);
        Assert::same(MissionState::VALIDATING, $mission->state()->toString());

        $mission->recordValidation(ValidationResult::passed('ok'), MissionFixtures::AT);
        Assert::same(MissionState::AWAITING_COMMIT_APPROVAL, $mission->state()->toString());

        $mission->approveCommit(MissionFixtures::commitApproval(true), MissionFixtures::AT);
        Assert::same(MissionState::COMMITTING, $mission->state()->toString());

        $mission->markPrReady(MissionFixtures::AT);
        Assert::same(MissionState::PR_READY, $mission->state()->toString());

        $mission->complete(MissionFixtures::AT);
        Assert::same(MissionState::COMPLETED, $mission->state()->toString());
    }

    public function test_reject_inspection_returns_to_inspecting(): void
    {
        $mission = MissionFixtures::newDraft('msn_rej_insp');
        $mission->defineScope(MissionFixtures::scope());
        $mission->startInspection(MissionFixtures::AT);
        $mission->submitInspection(MissionFixtures::findings(), MissionFixtures::AT);
        $mission->rejectInspection(MissionFixtures::inspectionApproval(false), MissionFixtures::AT);
        Assert::same(MissionState::INSPECTING, $mission->state()->toString());
    }

    public function test_validation_failure_enters_correction_loop_then_recovers(): void
    {
        $mission = MissionFixtures::toValidating('msn_corr');
        $mission->recordValidation(ValidationResult::failed('needs fix'), MissionFixtures::AT);
        Assert::same(MissionState::CORRECTION_LOOP, $mission->state()->toString());

        $mission->finishCorrection(MissionFixtures::AT);
        Assert::same(MissionState::VALIDATING, $mission->state()->toString());

        $mission->recordValidation(ValidationResult::passed('fixed'), MissionFixtures::AT);
        Assert::same(MissionState::AWAITING_COMMIT_APPROVAL, $mission->state()->toString());
    }

    public function test_reject_commit_enters_correction_loop_then_recovers(): void
    {
        $mission = MissionFixtures::toAwaitingCommitApproval('msn_rej_commit');
        $mission->rejectCommit(MissionFixtures::commitApproval(false), MissionFixtures::AT);
        Assert::same(MissionState::CORRECTION_LOOP, $mission->state()->toString());

        $mission->finishCorrection(MissionFixtures::AT);
        $mission->recordValidation(ValidationResult::passed('ok'), MissionFixtures::AT);
        $mission->approveCommit(MissionFixtures::commitApproval(true), MissionFixtures::AT);
        Assert::same(MissionState::COMMITTING, $mission->state()->toString());
    }

    public function test_define_scope_allowed_in_draft_and_inspecting(): void
    {
        $mission = MissionFixtures::newDraft('msn_scope');
        $mission->defineScope(MissionFixtures::scope());
        Assert::true($mission->scopePolicy() !== null);

        $mission->startInspection(MissionFixtures::AT);
        $mission->defineScope(new \Aep\Domain\Mission\ValueObject\ScopePolicy(
            ['src/Domain/Mission/Mission.php', 'src/Application/Mission/MissionCommandService.php']
        ));
        Assert::same(2, count($mission->scopePolicy()->allowedPaths()));
    }
}
