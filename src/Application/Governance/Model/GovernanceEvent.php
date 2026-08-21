<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Model;

final class GovernanceEvent
{
    public const RELEASE_CREATED = 'ReleaseCreated';
    public const RELEASE_APPROVED = 'ReleaseApproved';
    public const RELEASE_REJECTED = 'ReleaseRejected';
    public const DEPLOYMENT_STARTED = 'DeploymentStarted';
    public const DEPLOYMENT_FINISHED = 'DeploymentFinished';
    public const DEPLOYMENT_FAILED = 'DeploymentFailed';
    public const ROLLBACK_STARTED = 'RollbackStarted';
    public const ROLLBACK_COMPLETED = 'RollbackCompleted';
    public const APPROVAL_GRANTED = 'ApprovalGranted';
    public const APPROVAL_REJECTED = 'ApprovalRejected';
    public const QUALITY_GATE_FAILED = 'QualityGateFailed';
    public const QUALITY_GATE_PASSED = 'QualityGatePassed';
    public const COMPLIANCE_VIOLATION = 'ComplianceViolation';
    public const CHANGE_REQUEST_CREATED = 'ChangeRequestCreated';

    /** @param array<string, mixed> $payload */
    public function __construct(
        private string $eventId,
        private string $type,
        private string $atUtc,
        private array $payload = [],
        private ?string $releaseId = null,
        private ?string $actorId = null,
    ) {}

    public function eventId(): string { return $this->eventId; }
    public function type(): string { return $this->type; }
    public function atUtc(): string { return $this->atUtc; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'eventId' => $this->eventId,
            'type' => $this->type,
            'atUtc' => $this->atUtc,
            'releaseId' => $this->releaseId,
            'actorId' => $this->actorId,
            'payload' => $this->payload,
            'message' => $this->type,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['eventId'] ?? null) ? $data['eventId'] : self::makeId(),
            is_string($data['type'] ?? null) ? $data['type'] : '',
            is_string($data['atUtc'] ?? null) ? $data['atUtc'] : '',
            is_array($data['payload'] ?? null) ? $data['payload'] : [],
            is_string($data['releaseId'] ?? null) ? $data['releaseId'] : null,
            is_string($data['actorId'] ?? null) ? $data['actorId'] : null,
        );
    }

    public static function makeId(): string { return 'gev_' . bin2hex(random_bytes(8)); }
}
