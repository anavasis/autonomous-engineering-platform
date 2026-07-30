<?php

declare(strict_types=1);

namespace Aep\Application\Mission;

use Aep\Domain\Mission\MissionDomainEvent;
use Aep\Domain\Mission\ValueObject\MissionId;
use Aep\Domain\Mission\ValueObject\MissionState;

/**
 * Immutable application result after a successful command.
 */
final class MissionResult
{
    /**
     * @param list<MissionDomainEvent> $events
     */
    public function __construct(
        private MissionId $missionId,
        private MissionState $state,
        private array $events
    ) {
    }

    public function missionId(): MissionId
    {
        return $this->missionId;
    }

    public function state(): MissionState
    {
        return $this->state;
    }

    /**
     * @return list<MissionDomainEvent>
     */
    public function events(): array
    {
        return $this->events;
    }
}
