<?php

declare(strict_types=1);

namespace Aep\Application\Mission\Command;

/**
 * Submit inspection findings.
 */
final class SubmitInspection
{
    public function __construct(
        private string $missionId,
        private string $summary,
        private string $occurredAtUtc,
        private string $status = 'ready'
    ) {
        $this->missionId = self::requireNonEmpty($missionId, 'missionId');
        $this->summary = self::requireNonEmpty($summary, 'summary');
        $this->occurredAtUtc = self::requireNonEmpty($occurredAtUtc, 'occurredAtUtc');
        $this->status = self::requireNonEmpty($status, 'status');
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAtUtc;
    }

    private static function requireNonEmpty(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' must be non-empty.');
        }

        return $value;
    }
}
