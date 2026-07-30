<?php

declare(strict_types=1);

namespace Tests\Domain;

use Aep\Domain\Mission\ValueObject\ApprovalRecord;
use Aep\Domain\Mission\ValueObject\MissionState;
use Aep\Domain\Mission\ValueObject\TargetRepositoryRef;
use Aep\Domain\Mission\ValueObject\ValidationResult;
use Tests\Support\Assert;
use Tests\Support\MissionFixtures;

/**
 * Illegal transitions and Mission invariants.
 */
final class MissionInvariantsTest
{
    public function test_completed_is_terminal(): void
    {
        $mission = MissionFixtures::toPrReady('msn_term');
        $mission->complete(MissionFixtures::AT);
        Assert::same(MissionState::COMPLETED, $mission->state()->toString());

        Assert::throws(\DomainException::class, static function () use ($mission): void {
            $mission->startInspection(MissionFixtures::AT);
        });
        Assert::same(MissionState::COMPLETED, $mission->state()->toString());
    }

    public function test_cannot_skip_from_draft_to_complete(): void
    {
        $mission = MissionFixtures::newDraft('msn_skip');
        Assert::throws(\DomainException::class, static function () use ($mission): void {
            $mission->complete(MissionFixtures::AT);
        });
        Assert::same(MissionState::DRAFT, $mission->state()->toString());
    }

    public function test_cannot_implement_without_inspection_approval_path(): void
    {
        $mission = MissionFixtures::newDraft('msn_no_impl');
        $mission->defineScope(MissionFixtures::scope());
        $mission->startInspection(MissionFixtures::AT);
        Assert::throws(\DomainException::class, static function () use ($mission): void {
            $mission->finishImplementation(MissionFixtures::AT);
        });
        Assert::same(MissionState::INSPECTING, $mission->state()->toString());
    }

    public function test_approve_inspection_requires_scope(): void
    {
        $mission = MissionFixtures::newDraft('msn_no_scope');
        $mission->startInspection(MissionFixtures::AT);
        $mission->submitInspection(MissionFixtures::findings(), MissionFixtures::AT);
        Assert::throws(\DomainException::class, static function () use ($mission): void {
            $mission->approveInspection(MissionFixtures::inspectionApproval(true), MissionFixtures::AT);
        });
        Assert::same(MissionState::AWAITING_INSPECTION_APPROVAL, $mission->state()->toString());
    }

    public function test_approve_inspection_rejects_wrong_decision_record(): void
    {
        $mission = MissionFixtures::newDraft('msn_bad_dec');
        $mission->defineScope(MissionFixtures::scope());
        $mission->startInspection(MissionFixtures::AT);
        $mission->submitInspection(MissionFixtures::findings(), MissionFixtures::AT);
        Assert::throws(\DomainException::class, static function () use ($mission): void {
            $mission->approveInspection(MissionFixtures::inspectionApproval(false), MissionFixtures::AT);
        });
    }

    public function test_approve_inspection_rejects_wrong_subject(): void
    {
        $mission = MissionFixtures::newDraft('msn_bad_subj');
        $mission->defineScope(MissionFixtures::scope());
        $mission->startInspection(MissionFixtures::AT);
        $mission->submitInspection(MissionFixtures::findings(), MissionFixtures::AT);
        $wrong = new ApprovalRecord(
            ApprovalRecord::SUBJECT_COMMIT,
            ApprovalRecord::DECISION_APPROVED,
            MissionFixtures::actor(),
            MissionFixtures::AT
        );
        Assert::throws(\DomainException::class, static function () use ($mission, $wrong): void {
            $mission->approveInspection($wrong, MissionFixtures::AT);
        });
    }

    public function test_define_scope_forbidden_after_implementing(): void
    {
        $mission = MissionFixtures::toImplementing('msn_scope_lock');
        Assert::throws(\DomainException::class, static function () use ($mission): void {
            $mission->defineScope(MissionFixtures::scope());
        });
        Assert::same(MissionState::IMPLEMENTING, $mission->state()->toString());
    }

    public function test_record_validation_only_in_validating(): void
    {
        $mission = MissionFixtures::toImplementing('msn_val_state');
        Assert::throws(\DomainException::class, static function () use ($mission): void {
            $mission->recordValidation(ValidationResult::passed('ok'), MissionFixtures::AT);
        });
    }

    public function test_cannot_skip_pr_ready_from_committing(): void
    {
        $mission = MissionFixtures::toAwaitingCommitApproval('msn_skip_pr');
        $mission->approveCommit(MissionFixtures::commitApproval(true), MissionFixtures::AT);
        Assert::throws(\DomainException::class, static function () use ($mission): void {
            $mission->complete(MissionFixtures::AT);
        });
        Assert::same(MissionState::COMMITTING, $mission->state()->toString());
    }

    public function test_identity_fields_immutable_after_create(): void
    {
        $mission = MissionFixtures::newDraft('msn_id');
        $id = $mission->id()->toString();
        $target = $mission->target()->toString();
        $brief = $mission->brief()->objective();
        $createdBy = $mission->createdBy()->toString();
        $createdAt = $mission->createdAtUtc();

        $mission->defineScope(MissionFixtures::scope());
        $mission->startInspection(MissionFixtures::AT);

        Assert::same($id, $mission->id()->toString());
        Assert::same($target, $mission->target()->toString());
        Assert::same($brief, $mission->brief()->objective());
        Assert::same($createdBy, $mission->createdBy()->toString());
        Assert::same($createdAt, $mission->createdAtUtc());
    }

    public function test_target_rejects_credential_hint(): void
    {
        Assert::throws(\InvalidArgumentException::class, static function (): void {
            new TargetRepositoryRef('github', 'https://user:pass@github.com/org/repo');
        });
    }

    public function test_validation_failed_requires_reason(): void
    {
        Assert::throws(\InvalidArgumentException::class, static function (): void {
            ValidationResult::failed('   ');
        });
    }
}
