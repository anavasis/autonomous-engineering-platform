<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringWorkspace\Model;

final class EngineeringWorkspace
{
    public const SCHEMA_VERSION = 1;

    public const STATUS_PLANNED = 'planned';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_READY = 'ready';
    public const STATUS_IN_USE = 'in_use';
    public const STATUS_SNAPSHOTTING = 'snapshotting';
    public const STATUS_SEALED = 'sealed';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_PURGED = 'purged';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CORRUPT = 'corrupt';
    public const STATUS_QUOTA_EXCEEDED = 'quota_exceeded';

    /**
     * @param list<string> $sessionIds
     * @param list<string> $allowedPaths
     * @param array<string, mixed> $compatibility
     * @param array<string, mixed> $git
     * @param list<string> $envNames
     * @param list<string> $snapshotIds
     */
    public function __construct(
        private string $workspaceId,
        private string $missionId,
        private string $runId,
        private string $status,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private string $rootPath = '',
        private ?string $projectId = null,
        private array $sessionIds = [],
        private array $allowedPaths = ['src/'],
        private WorkspaceMounts $mounts = new WorkspaceMounts(),
        private WorkspaceQuota $quota = new WorkspaceQuota(),
        private WorkspaceHealth $health = new WorkspaceHealth('unavailable'),
        private string $reproFingerprint = '',
        private array $compatibility = [],
        private array $git = [],
        private array $envNames = [],
        private ?string $activeSnapshotId = null,
        private array $snapshotIds = [],
        private string $message = '',
        private int $schemaVersion = self::SCHEMA_VERSION,
    ) {
    }

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function projectId(): ?string
    {
        return $this->projectId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function setRootPath(string $path): void
    {
        $this->rootPath = $path;
    }

    /** @return list<string> */
    public function sessionIds(): array
    {
        return $this->sessionIds;
    }

    public function attachSession(string $sessionId): void
    {
        if ($sessionId !== '' && !in_array($sessionId, $this->sessionIds, true)) {
            $this->sessionIds[] = $sessionId;
        }
    }

    /** @return list<string> */
    public function allowedPaths(): array
    {
        return $this->allowedPaths;
    }

    public function mounts(): WorkspaceMounts
    {
        return $this->mounts;
    }

    public function quota(): WorkspaceQuota
    {
        return $this->quota;
    }

    public function health(): WorkspaceHealth
    {
        return $this->health;
    }

    public function setHealth(WorkspaceHealth $health): void
    {
        $this->health = $health;
    }

    public function reproFingerprint(): string
    {
        return $this->reproFingerprint;
    }

    public function setReproFingerprint(string $fingerprint): void
    {
        $this->reproFingerprint = $fingerprint;
    }

    /** @return array<string, mixed> */
    public function git(): array
    {
        return $this->git;
    }

    /** @param array<string, mixed> $git */
    public function setGit(array $git): void
    {
        $this->git = $git;
    }

    public function activeSnapshotId(): ?string
    {
        return $this->activeSnapshotId;
    }

    public function setActiveSnapshotId(?string $snapshotId): void
    {
        $this->activeSnapshotId = $snapshotId;
        if ($snapshotId !== null && $snapshotId !== '' && !in_array($snapshotId, $this->snapshotIds, true)) {
            $this->snapshotIds[] = $snapshotId;
        }
    }

    /** @return list<string> */
    public function snapshotIds(): array
    {
        return $this->snapshotIds;
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

    /** @param list<string> $names */
    public function setEnvNames(array $names): void
    {
        $this->envNames = $names;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'workspaceId' => $this->workspaceId,
            'missionId' => $this->missionId,
            'runId' => $this->runId,
            'projectId' => $this->projectId,
            'status' => $this->status,
            'message' => $this->message,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
            'rootPath' => $this->rootPath,
            'sessionIds' => $this->sessionIds,
            'allowedPaths' => $this->allowedPaths,
            'mounts' => $this->mounts->toArray(),
            'quota' => $this->quota->toArray(),
            'health' => $this->health->toArray(),
            'reproducibilityFingerprint' => $this->reproFingerprint,
            'compatibility' => $this->compatibility !== [] ? $this->compatibility : [
                'aepMinVersion' => '0.4.0',
                'executionContract' => 'EngineeringExecutionProvider@0.3',
                'artifactPlane' => 'ArtifactWorkspace@0.1',
                'gitPlane' => 'GitMVP@0.1',
            ],
            'git' => $this->git,
            'envNames' => $this->envNames,
            'activeSnapshotId' => $this->activeSnapshotId,
            'snapshotIds' => $this->snapshotIds,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $sessions = [];
        if (isset($data['sessionIds']) && is_array($data['sessionIds'])) {
            foreach ($data['sessionIds'] as $s) {
                if (is_string($s)) {
                    $sessions[] = $s;
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
        $env = [];
        if (isset($data['envNames']) && is_array($data['envNames'])) {
            foreach ($data['envNames'] as $n) {
                if (is_string($n)) {
                    $env[] = $n;
                }
            }
        }
        $snapshots = [];
        if (isset($data['snapshotIds']) && is_array($data['snapshotIds'])) {
            foreach ($data['snapshotIds'] as $s) {
                if (is_string($s)) {
                    $snapshots[] = $s;
                }
            }
        }

        return new self(
            is_string($data['workspaceId'] ?? null) ? $data['workspaceId'] : '',
            is_string($data['missionId'] ?? null) ? $data['missionId'] : '',
            is_string($data['runId'] ?? null) ? $data['runId'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_PLANNED,
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            is_string($data['rootPath'] ?? null) ? $data['rootPath'] : '',
            isset($data['projectId']) && is_string($data['projectId']) ? $data['projectId'] : null,
            $sessions,
            $allowed !== [] ? $allowed : ['src/'],
            isset($data['mounts']) && is_array($data['mounts']) ? WorkspaceMounts::fromArray($data['mounts']) : new WorkspaceMounts(),
            isset($data['quota']) && is_array($data['quota']) ? WorkspaceQuota::fromArray($data['quota']) : new WorkspaceQuota(),
            isset($data['health']) && is_array($data['health']) ? WorkspaceHealth::fromArray($data['health']) : new WorkspaceHealth('unavailable'),
            is_string($data['reproducibilityFingerprint'] ?? null) ? $data['reproducibilityFingerprint'] : '',
            is_array($data['compatibility'] ?? null) ? $data['compatibility'] : [],
            is_array($data['git'] ?? null) ? $data['git'] : [],
            $env,
            isset($data['activeSnapshotId']) && is_string($data['activeSnapshotId']) ? $data['activeSnapshotId'] : null,
            $snapshots,
            is_string($data['message'] ?? null) ? $data['message'] : '',
            is_int($data['schemaVersion'] ?? null) ? $data['schemaVersion'] : self::SCHEMA_VERSION,
        );
    }
}
