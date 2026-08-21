<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Model;

final class MissionIntake
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CLARIFYING = 'clarifying';
    public const STATUS_READY = 'ready';
    public const STATUS_LAUNCHED = 'launched';
    public const STATUS_REJECTED = 'rejected';

    /**
     * @param list<ClarificationQuestion> $questions
     */
    public function __construct(
        private string $id,
        private string $actorId,
        private string $rawText,
        private string $status,
        private string $conversationId,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private ?MissionIntent $intent = null,
        private array $questions = [],
        private ?string $rejectReason = null,
        private ?string $clientRequestId = null,
        private ?string $projectIdHint = null,
        private ?string $missionId = null,
        private ?string $runId = null,
        private ?string $planId = null,
    ) {
        $this->id = trim($id);
        $this->actorId = trim($actorId);
        $this->rawText = trim($rawText);
        $this->conversationId = trim($conversationId);
        if ($this->id === '' || $this->actorId === '' || $this->rawText === '' || $this->conversationId === '') {
            throw new \InvalidArgumentException('MissionIntake requires id, actorId, rawText, conversationId.');
        }
        $this->assertStatus($status);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function actorId(): string
    {
        return $this->actorId;
    }

    public function rawText(): string
    {
        return $this->rawText;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function conversationId(): string
    {
        return $this->conversationId;
    }

    public function createdAtUtc(): string
    {
        return $this->createdAtUtc;
    }

    public function updatedAtUtc(): string
    {
        return $this->updatedAtUtc;
    }

    public function intent(): ?MissionIntent
    {
        return $this->intent;
    }

    /** @return list<ClarificationQuestion> */
    public function questions(): array
    {
        return $this->questions;
    }

    public function rejectReason(): ?string
    {
        return $this->rejectReason;
    }

    public function clientRequestId(): ?string
    {
        return $this->clientRequestId;
    }

    public function projectIdHint(): ?string
    {
        return $this->projectIdHint;
    }

    public function missionId(): ?string
    {
        return $this->missionId;
    }

    public function runId(): ?string
    {
        return $this->runId;
    }

    public function planId(): ?string
    {
        return $this->planId;
    }

    public function setIntent(MissionIntent $intent, string $atUtc): void
    {
        $this->intent = $intent;
        $this->updatedAtUtc = $atUtc;
    }

    /** @param list<ClarificationQuestion> $questions */
    public function markClarifying(array $questions, string $atUtc): void
    {
        $this->status = self::STATUS_CLARIFYING;
        $this->questions = $questions;
        $this->updatedAtUtc = $atUtc;
    }

    public function markReady(string $atUtc): void
    {
        $this->status = self::STATUS_READY;
        $this->questions = [];
        $this->updatedAtUtc = $atUtc;
    }

    public function markRejected(string $reason, string $atUtc): void
    {
        $this->status = self::STATUS_REJECTED;
        $this->rejectReason = $reason;
        $this->updatedAtUtc = $atUtc;
    }

    public function markLaunched(string $missionId, string $runId, string $planId, string $atUtc): void
    {
        $this->status = self::STATUS_LAUNCHED;
        $this->missionId = $missionId;
        $this->runId = $runId;
        $this->planId = $planId;
        $this->updatedAtUtc = $atUtc;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'actorId' => $this->actorId,
            'rawText' => $this->rawText,
            'status' => $this->status,
            'conversationId' => $this->conversationId,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
            'intent' => $this->intent?->toArray(),
            'questions' => array_map(static fn (ClarificationQuestion $q) => $q->toArray(), $this->questions),
            'rejectReason' => $this->rejectReason,
            'clientRequestId' => $this->clientRequestId,
            'projectIdHint' => $this->projectIdHint,
            'missionId' => $this->missionId,
            'runId' => $this->runId,
            'planId' => $this->planId,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $questions = [];
        if (isset($data['questions']) && is_array($data['questions'])) {
            foreach ($data['questions'] as $row) {
                if (is_array($row)) {
                    $questions[] = ClarificationQuestion::fromArray($row);
                }
            }
        }
        $intent = isset($data['intent']) && is_array($data['intent'])
            ? MissionIntent::fromArray($data['intent'])
            : null;

        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : '',
            is_string($data['actorId'] ?? null) ? $data['actorId'] : '',
            is_string($data['rawText'] ?? null) ? $data['rawText'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_RECEIVED,
            is_string($data['conversationId'] ?? null) ? $data['conversationId'] : '',
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            $intent,
            $questions,
            isset($data['rejectReason']) && is_string($data['rejectReason']) ? $data['rejectReason'] : null,
            isset($data['clientRequestId']) && is_string($data['clientRequestId']) ? $data['clientRequestId'] : null,
            isset($data['projectIdHint']) && is_string($data['projectIdHint']) ? $data['projectIdHint'] : null,
            isset($data['missionId']) && is_string($data['missionId']) ? $data['missionId'] : null,
            isset($data['runId']) && is_string($data['runId']) ? $data['runId'] : null,
            isset($data['planId']) && is_string($data['planId']) ? $data['planId'] : null,
        );
    }

    private function assertStatus(string $status): void
    {
        $allowed = [
            self::STATUS_RECEIVED,
            self::STATUS_CLARIFYING,
            self::STATUS_READY,
            self::STATUS_LAUNCHED,
            self::STATUS_REJECTED,
        ];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid intake status: ' . $status);
        }
        $this->status = $status;
    }
}
