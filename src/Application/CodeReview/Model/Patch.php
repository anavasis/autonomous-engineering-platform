<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Model;

final class Patch
{
    public const SCHEMA_VERSION = 1;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_COLLECTING = 'collecting';
    public const STATUS_VALIDATING = 'validating';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_CHANGES_REQUESTED = 'changes_requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_MERGE_READY = 'merge_ready';
    public const STATUS_SEALED = 'sealed';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CONFLICTED = 'conflicted';
    public const STATUS_STALE = 'stale';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    /**
     * @param list<CheckRun> $checks
     * @param list<ReviewRecord> $reviews
     * @param array<string, mixed> $compatibility
     * @param array<string, mixed> $rollback
     * @param list<string> $allowedPaths
     */
    public function __construct(
        private string $patchId,
        private string $missionId,
        private string $runId,
        private string $status,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private string $diffText = '',
        private ?string $workspaceId = null,
        private ?string $sessionId = null,
        private ChangeManifest $manifest = new ChangeManifest(),
        private array $checks = [],
        private array $reviews = [],
        private int $score = 0,
        private string $grade = 'F',
        private MergeReadiness $mergeReadiness = new MergeReadiness(false),
        private string $reproFingerprint = '',
        private string $integrityHash = '',
        private array $compatibility = [],
        private array $rollback = [],
        private array $allowedPaths = ['src/'],
        private string $message = '',
        private int $schemaVersion = self::SCHEMA_VERSION,
    ) {
    }

    public function patchId(): string
    {
        return $this->patchId;
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function diffText(): string
    {
        return $this->diffText;
    }

    public function setDiffText(string $diff): void
    {
        $this->diffText = $diff;
    }

    public function workspaceId(): ?string
    {
        return $this->workspaceId;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function manifest(): ChangeManifest
    {
        return $this->manifest;
    }

    public function setManifest(ChangeManifest $manifest): void
    {
        $this->manifest = $manifest;
    }

    /** @return list<CheckRun> */
    public function checks(): array
    {
        return $this->checks;
    }

    public function addCheck(CheckRun $check): void
    {
        $this->checks[] = $check;
    }

    /** @param list<CheckRun> $checks */
    public function setChecks(array $checks): void
    {
        $this->checks = $checks;
    }

    /** @return list<ReviewRecord> */
    public function reviews(): array
    {
        return $this->reviews;
    }

    public function addReview(ReviewRecord $review): void
    {
        $this->reviews[] = $review;
    }

    public function score(): int
    {
        return $this->score;
    }

    public function setScore(int $score, string $grade): void
    {
        $this->score = max(0, min(100, $score));
        $this->grade = $grade;
    }

    public function mergeReadiness(): MergeReadiness
    {
        return $this->mergeReadiness;
    }

    public function setMergeReadiness(MergeReadiness $readiness): void
    {
        $this->mergeReadiness = $readiness;
    }

    public function reproFingerprint(): string
    {
        return $this->reproFingerprint;
    }

    public function setReproFingerprint(string $fp): void
    {
        $this->reproFingerprint = $fp;
    }

    public function integrityHash(): string
    {
        return $this->integrityHash;
    }

    public function setIntegrityHash(string $hash): void
    {
        $this->integrityHash = $hash;
    }

    /** @return list<string> */
    public function allowedPaths(): array
    {
        return $this->allowedPaths;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function setStatus(string $status, string $atUtc, string $message = ''): void
    {
        $this->status = $status;
        $this->updatedAtUtc = $atUtc;
        if ($message !== '') {
            $this->message = $message;
        }
    }

    /** @param array<string, mixed> $rollback */
    public function setRollback(array $rollback): void
    {
        $this->rollback = $rollback;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'patchId' => $this->patchId,
            'missionId' => $this->missionId,
            'runId' => $this->runId,
            'workspaceId' => $this->workspaceId,
            'sessionId' => $this->sessionId,
            'status' => $this->status,
            'message' => $this->message,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
            'allowedPaths' => $this->allowedPaths,
            'manifest' => $this->manifest->toArray(),
            'checks' => array_map(static fn (CheckRun $c): array => $c->toArray(), $this->checks),
            'reviews' => array_map(static fn (ReviewRecord $r): array => $r->toArray(), $this->reviews),
            'score' => $this->score,
            'grade' => $this->grade,
            'mergeReadiness' => $this->mergeReadiness->toArray(),
            'reproducibilityFingerprint' => $this->reproFingerprint,
            'integrityHash' => $this->integrityHash,
            'compatibility' => $this->compatibility !== [] ? $this->compatibility : [
                'aepMinVersion' => '0.5.0',
                'executionContract' => 'EngineeringExecutionProvider@0.3',
                'workspacePlane' => 'EngineeringWorkspace@0.4',
                'missionFsm' => 'MissionDomain@1',
                'schemaVersion' => self::SCHEMA_VERSION,
            ],
            'rollback' => $this->rollback,
            'diffBytes' => strlen($this->diffText),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $diffText = ''): self
    {
        $checks = [];
        if (isset($data['checks']) && is_array($data['checks'])) {
            foreach ($data['checks'] as $c) {
                if (is_array($c)) {
                    $checks[] = CheckRun::fromArray($c);
                }
            }
        }
        $reviews = [];
        if (isset($data['reviews']) && is_array($data['reviews'])) {
            foreach ($data['reviews'] as $r) {
                if (is_array($r)) {
                    $reviews[] = ReviewRecord::fromArray($r);
                }
            }
        }
        $allowed = [];
        if (isset($data['allowedPaths']) && is_array($data['allowedPaths'])) {
            foreach ($data['allowedPaths'] as $p) {
                if (is_string($p)) {
                    $allowed[] = $p;
                }
            }
        }

        return new self(
            is_string($data['patchId'] ?? null) ? $data['patchId'] : '',
            is_string($data['missionId'] ?? null) ? $data['missionId'] : '',
            is_string($data['runId'] ?? null) ? $data['runId'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_DRAFT,
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            $diffText,
            isset($data['workspaceId']) && is_string($data['workspaceId']) ? $data['workspaceId'] : null,
            isset($data['sessionId']) && is_string($data['sessionId']) ? $data['sessionId'] : null,
            isset($data['manifest']) && is_array($data['manifest']) ? ChangeManifest::fromArray($data['manifest']) : new ChangeManifest(),
            $checks,
            $reviews,
            is_int($data['score'] ?? null) ? $data['score'] : 0,
            is_string($data['grade'] ?? null) ? $data['grade'] : 'F',
            isset($data['mergeReadiness']) && is_array($data['mergeReadiness'])
                ? MergeReadiness::fromArray($data['mergeReadiness']) : new MergeReadiness(false),
            is_string($data['reproducibilityFingerprint'] ?? null) ? $data['reproducibilityFingerprint'] : '',
            is_string($data['integrityHash'] ?? null) ? $data['integrityHash'] : '',
            is_array($data['compatibility'] ?? null) ? $data['compatibility'] : [],
            is_array($data['rollback'] ?? null) ? $data['rollback'] : [],
            $allowed !== [] ? $allowed : ['src/'],
            is_string($data['message'] ?? null) ? $data['message'] : '',
            is_int($data['schemaVersion'] ?? null) ? $data['schemaVersion'] : self::SCHEMA_VERSION,
        );
    }
}
