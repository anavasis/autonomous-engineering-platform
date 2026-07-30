<?php

declare(strict_types=1);

namespace Aep\Application\Mission\Command;

/**
 * Shared payload for commands that only need mission id + UTC timestamp.
 */
final class TimedMissionCommand
{
    public function __construct(
        private string $missionId,
        private string $occurredAtUtc
    ) {
        $this->missionId = self::requireNonEmpty($missionId, 'missionId');
        $this->occurredAtUtc = self::requireNonEmpty($occurredAtUtc, 'occurredAtUtc');
    }

    public function missionId(): string
    {
        return $this->missionId;
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
