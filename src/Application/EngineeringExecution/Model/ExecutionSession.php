<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Model;

final class ExecutionSession
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_PREPARING = 'preparing_workspace';
    public const STATUS_PROMPTING = 'prompting';
    public const STATUS_RUNNING = 'running';
    public const STATUS_STREAMING = 'streaming';
    public const STATUS_COLLECTING = 'collecting_diffs';
    public const STATUS_CAPTURING = 'capturing_artifacts';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_TIMED_OUT = 'timed_out';

    /**
     * @param list<string> $timeline
     * @param list<string> $fallbackChain
     * @param array<string, mixed>|null $checkpoint
     * @param array<string, mixed> $artifacts
     */
    public function __construct(
        private string $sessionId,
        private string $missionId,
        private string $runId,
        private string $providerId,
        private string $status,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private string $message = '',
        private array $timeline = [],
        private array $fallbackChain = [],
        private ?PromptBundle $prompt = null,
        private UsageMetrics $usage = new UsageMetrics(),
        private ?array $checkpoint = null,
        private array $artifacts = [],
        private bool $cancelRequested = false,
        private ?string $legacyBypass = null,
    ) {
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function providerId(): string
    {
        return $this->providerId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function prompt(): ?PromptBundle
    {
        return $this->prompt;
    }

    public function usage(): UsageMetrics
    {
        return $this->usage;
    }

    /** @return list<string> */
    public function timeline(): array
    {
        return $this->timeline;
    }

    /** @return array<string, mixed>|null */
    public function checkpoint(): ?array
    {
        return $this->checkpoint;
    }

    /** @return array<string, mixed> */
    public function artifacts(): array
    {
        return $this->artifacts;
    }

    public function cancelRequested(): bool
    {
        return $this->cancelRequested;
    }

    public function setStatus(string $status, string $atUtc, string $message = ''): void
    {
        $this->status = $status;
        $this->updatedAtUtc = $atUtc;
        if ($message !== '') {
            $this->message = $message;
        }
        $this->timeline[] = $atUtc . ' ' . $status . ($message !== '' ? ' — ' . $message : '');
    }

    public function setPrompt(PromptBundle $prompt): void
    {
        $this->prompt = $prompt;
    }

    public function setProviderId(string $providerId): void
    {
        $this->providerId = $providerId;
    }

    public function requestCancel(): void
    {
        $this->cancelRequested = true;
    }

    /** @param array<string, mixed> $checkpoint */
    public function setCheckpoint(array $checkpoint): void
    {
        $this->checkpoint = $checkpoint;
    }

    /** @param array<string, mixed> $artifacts */
    public function setArtifacts(array $artifacts): void
    {
        $this->artifacts = $artifacts;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sessionId' => $this->sessionId,
            'missionId' => $this->missionId,
            'runId' => $this->runId,
            'providerId' => $this->providerId,
            'status' => $this->status,
            'message' => $this->message,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
            'timeline' => $this->timeline,
            'fallbackChain' => $this->fallbackChain,
            'prompt' => $this->prompt?->toArray(),
            'usage' => $this->usage->toArray(),
            'checkpoint' => $this->checkpoint,
            'artifacts' => $this->artifacts,
            'cancelRequested' => $this->cancelRequested,
            'legacyBypass' => $this->legacyBypass,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $prompt = isset($data['prompt']) && is_array($data['prompt'])
            ? PromptBundle::fromArray($data['prompt'])
            : null;
        $usage = isset($data['usage']) && is_array($data['usage'])
            ? UsageMetrics::fromArray($data['usage'])
            : new UsageMetrics();
        $timeline = [];
        if (isset($data['timeline']) && is_array($data['timeline'])) {
            foreach ($data['timeline'] as $t) {
                if (is_string($t)) {
                    $timeline[] = $t;
                }
            }
        }
        $fallback = [];
        if (isset($data['fallbackChain']) && is_array($data['fallbackChain'])) {
            foreach ($data['fallbackChain'] as $f) {
                if (is_string($f)) {
                    $fallback[] = $f;
                }
            }
        }

        return new self(
            is_string($data['sessionId'] ?? null) ? $data['sessionId'] : '',
            is_string($data['missionId'] ?? null) ? $data['missionId'] : '',
            is_string($data['runId'] ?? null) ? $data['runId'] : '',
            is_string($data['providerId'] ?? null) ? $data['providerId'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_PLANNED,
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            is_string($data['message'] ?? null) ? $data['message'] : '',
            $timeline,
            $fallback,
            $prompt,
            $usage,
            isset($data['checkpoint']) && is_array($data['checkpoint']) ? $data['checkpoint'] : null,
            is_array($data['artifacts'] ?? null) ? $data['artifacts'] : [],
            ($data['cancelRequested'] ?? false) === true,
            isset($data['legacyBypass']) && is_string($data['legacyBypass']) ? $data['legacyBypass'] : null,
        );
    }
}
