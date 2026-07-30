<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

/**
 * Single MissionTimeline row.
 */
final class MissionTimelineEntry
{
    public function __construct(
        private string $timestamp,
        private string $step,
        private string $event,
        private string $status,
        private float $duration,
        private string $message
    ) {
    }

    public function timestamp(): string
    {
        return $this->timestamp;
    }

    public function step(): string
    {
        return $this->step;
    }

    public function event(): string
    {
        return $this->event;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function duration(): float
    {
        return $this->duration;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'step' => $this->step,
            'event' => $this->event,
            'status' => $this->status,
            'duration' => $this->duration,
            'message' => $this->message,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['timestamp'] ?? null) ? $data['timestamp'] : '',
            is_string($data['step'] ?? null) ? $data['step'] : '',
            is_string($data['event'] ?? null) ? $data['event'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : '',
            is_float($data['duration'] ?? null) || is_int($data['duration'] ?? null) ? (float) $data['duration'] : 0.0,
            is_string($data['message'] ?? null) ? $data['message'] : '',
        );
    }
}
