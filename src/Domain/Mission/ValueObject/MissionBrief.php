<?php

declare(strict_types=1);

namespace Aep\Domain\Mission\ValueObject;

/**
 * Mission objective.
 */
final class MissionBrief
{
    public function __construct(
        private string $objective
    ) {
        $objective = trim($objective);
        if ($objective === '') {
            throw new \InvalidArgumentException('MissionBrief objective must be non-empty.');
        }
        if (strlen($objective) > 4000) {
            throw new \InvalidArgumentException('MissionBrief objective exceeds maximum length.');
        }
        $this->objective = $objective;
    }

    public function objective(): string
    {
        return $this->objective;
    }
}
