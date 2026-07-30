<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Persistence;

use Aep\Domain\Mission\Mission;
use Aep\Domain\Mission\MissionRepository;
use Aep\Domain\Mission\ValueObject\ActorRef;
use Aep\Domain\Mission\ValueObject\ApprovalRecord;
use Aep\Domain\Mission\ValueObject\InspectionFindings;
use Aep\Domain\Mission\ValueObject\MissionBrief;
use Aep\Domain\Mission\ValueObject\MissionId;
use Aep\Domain\Mission\ValueObject\MissionState;
use Aep\Domain\Mission\ValueObject\ScopePolicy;
use Aep\Domain\Mission\ValueObject\TargetRepositoryRef;
use Aep\Domain\Mission\ValueObject\ValidationResult;

/**
 * Durable JSON-file MissionRepository. Atomic upsert via temp file + rename.
 */
final class JsonFileMissionRepository implements MissionRepository
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private string $directory
    ) {
        $directory = rtrim($directory, "/\\");
        if ($directory === '') {
            throw new \InvalidArgumentException('JsonFileMissionRepository directory must be non-empty.');
        }
        $this->directory = $directory;
    }

    public function get(MissionId $id): Mission
    {
        $path = $this->pathFor($id);
        if (!is_file($path)) {
            throw new \RuntimeException('Mission not found: ' . $id->toString());
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read mission file: ' . $path);
        }

        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid mission JSON for ' . $id->toString(), 0, $e);
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Mission JSON must decode to an object for ' . $id->toString());
        }

        return $this->fromSnapshot($data, $id);
    }

    public function save(Mission $mission): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create mission storage directory: ' . $this->directory);
        }

        $path = $this->pathFor($mission->id());
        $payload = json_encode(
            $this->toSnapshot($mission),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );

        $temp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temp, $payload) === false) {
            throw new \RuntimeException('Unable to write temporary mission file: ' . $temp);
        }

        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to atomically replace mission file: ' . $path);
        }
    }

    public function exists(MissionId $id): bool
    {
        return is_file($this->pathFor($id));
    }

    private function pathFor(MissionId $id): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->safeFileName($id->toString()) . '.json';
    }

    private function safeFileName(string $missionId): string
    {
        if ($missionId === '' || preg_match('/[^A-Za-z0-9_-]/', $missionId) === 1) {
            throw new \InvalidArgumentException('MissionId is not safe for filesystem persistence: ' . $missionId);
        }

        return $missionId;
    }

    /**
     * @return array<string, mixed>
     */
    private function toSnapshot(Mission $mission): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'id' => $mission->id()->toString(),
            'state' => $mission->state()->toString(),
            'target' => [
                'provider' => $mission->target()->provider(),
                'repository' => $mission->target()->repository(),
            ],
            'brief' => [
                'objective' => $mission->brief()->objective(),
            ],
            'createdBy' => [
                'type' => $mission->createdBy()->type(),
                'id' => $mission->createdBy()->id(),
            ],
            'createdAtUtc' => $mission->createdAtUtc(),
            'scopePolicy' => $this->scopeToArray($mission->scopePolicy()),
            'inspectionFindings' => $this->findingsToArray($mission->inspectionFindings()),
            'inspectionApproval' => $this->approvalToArray($mission->inspectionApproval()),
            'commitApproval' => $this->approvalToArray($mission->commitApproval()),
            'lastValidationResult' => $this->validationToArray($mission->lastValidationResult()),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fromSnapshot(array $data, MissionId $expectedId): Mission
    {
        $version = $data['schemaVersion'] ?? null;
        if ($version !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('Unsupported mission schemaVersion: ' . self::exportScalar($version));
        }

        $idValue = $this->requireString($data, 'id');
        if ($idValue !== $expectedId->toString()) {
            throw new \RuntimeException('Mission file id mismatch.');
        }

        return Mission::reconstitute(
            new MissionId($idValue),
            new TargetRepositoryRef(
                $this->requireString($data['target'] ?? [], 'provider'),
                $this->requireString($data['target'] ?? [], 'repository')
            ),
            new MissionBrief($this->requireString($data['brief'] ?? [], 'objective')),
            new ActorRef(
                $this->requireString($data['createdBy'] ?? [], 'type'),
                $this->requireString($data['createdBy'] ?? [], 'id')
            ),
            $this->requireString($data, 'createdAtUtc'),
            new MissionState($this->requireString($data, 'state')),
            $this->scopeFromArray($data['scopePolicy'] ?? null),
            $this->findingsFromArray($data['inspectionFindings'] ?? null),
            $this->approvalFromArray($data['inspectionApproval'] ?? null),
            $this->approvalFromArray($data['commitApproval'] ?? null),
            $this->validationFromArray($data['lastValidationResult'] ?? null)
        );
    }

    /**
     * @return array{allowedPaths: list<string>, nonGoals: list<string>}|null
     */
    private function scopeToArray(?ScopePolicy $scope): ?array
    {
        if ($scope === null) {
            return null;
        }

        return [
            'allowedPaths' => $scope->allowedPaths(),
            'nonGoals' => $scope->nonGoals(),
        ];
    }

    private function scopeFromArray(mixed $data): ?ScopePolicy
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data)) {
            throw new \RuntimeException('scopePolicy must be an object or null.');
        }
        $paths = $data['allowedPaths'] ?? null;
        $nonGoals = $data['nonGoals'] ?? [];
        if (!is_array($paths) || !is_array($nonGoals)) {
            throw new \RuntimeException('scopePolicy paths/nonGoals must be arrays.');
        }

        /** @var list<string> $paths */
        /** @var list<string> $nonGoals */
        return new ScopePolicy($paths, $nonGoals);
    }

    /**
     * @return array{summary: string, status: string}|null
     */
    private function findingsToArray(?InspectionFindings $findings): ?array
    {
        if ($findings === null) {
            return null;
        }

        return [
            'summary' => $findings->summary(),
            'status' => $findings->status(),
        ];
    }

    private function findingsFromArray(mixed $data): ?InspectionFindings
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data)) {
            throw new \RuntimeException('inspectionFindings must be an object or null.');
        }

        return new InspectionFindings(
            $this->requireString($data, 'summary'),
            $this->requireString($data, 'status')
        );
    }

    /**
     * @return array{subject: string, decision: string, actor: array{type: string, id: string}, occurredAtUtc: string, rationale: string}|null
     */
    private function approvalToArray(?ApprovalRecord $approval): ?array
    {
        if ($approval === null) {
            return null;
        }

        return [
            'subject' => $approval->subject(),
            'decision' => $approval->decision(),
            'actor' => [
                'type' => $approval->actor()->type(),
                'id' => $approval->actor()->id(),
            ],
            'occurredAtUtc' => $approval->occurredAtUtc(),
            'rationale' => $approval->rationale(),
        ];
    }

    private function approvalFromArray(mixed $data): ?ApprovalRecord
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data)) {
            throw new \RuntimeException('approval must be an object or null.');
        }

        return new ApprovalRecord(
            $this->requireString($data, 'subject'),
            $this->requireString($data, 'decision'),
            new ActorRef(
                $this->requireString($data['actor'] ?? [], 'type'),
                $this->requireString($data['actor'] ?? [], 'id')
            ),
            $this->requireString($data, 'occurredAtUtc'),
            is_string($data['rationale'] ?? '') ? (string) $data['rationale'] : ''
        );
    }

    /**
     * @return array{outcome: string, reason: string}|null
     */
    private function validationToArray(?ValidationResult $result): ?array
    {
        if ($result === null) {
            return null;
        }

        return [
            'outcome' => $result->outcome(),
            'reason' => $result->reason(),
        ];
    }

    private function validationFromArray(mixed $data): ?ValidationResult
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data)) {
            throw new \RuntimeException('lastValidationResult must be an object or null.');
        }

        $outcome = $this->requireString($data, 'outcome');
        $reason = is_string($data['reason'] ?? '') ? (string) $data['reason'] : '';

        return $outcome === ValidationResult::PASSED
            ? ValidationResult::passed($reason)
            : ValidationResult::failed($reason);
    }

    /**
     * @param array<mixed> $data
     */
    private function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException('Missing or invalid snapshot field: ' . $key);
        }

        return $value;
    }

    private static function exportScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return gettype($value);
    }
}
