<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Execution\Ssh;

/**
 * Redacted SSH audit row.
 */
final class SSHAuditLogEntry
{
    public function __construct(
        private string $timestamp,
        private string $missionId,
        private string $host,
        private string $user,
        private string $authMethod,
        private string $command,
        private string $status,
        private ?int $exitCode,
        private float $durationMs,
        private string $message,
        private ?string $serverId = null,
        private ?string $runId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'missionId' => $this->missionId,
            'runId' => $this->runId,
            'serverId' => $this->serverId,
            'host' => $this->host,
            'user' => $this->user,
            'authMethod' => $this->authMethod,
            'command' => $this->command,
            'status' => $this->status,
            'exitCode' => $this->exitCode,
            'durationMs' => $this->durationMs,
            'message' => $this->message,
        ];
    }
}
