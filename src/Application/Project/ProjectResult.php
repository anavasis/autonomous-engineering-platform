<?php

declare(strict_types=1);

namespace Aep\Application\Project;

use Aep\Domain\Project\ProjectDomainEvent;
use Aep\Domain\Project\ValueObject\ProjectId;
use Aep\Domain\Project\ValueObject\ProjectStatus;

final class ProjectResult
{
    /**
     * @param list<ProjectDomainEvent> $events
     */
    public function __construct(
        private ProjectId $projectId,
        private ProjectStatus $status,
        private array $events
    ) {
    }

    public function projectId(): ProjectId
    {
        return $this->projectId;
    }

    public function status(): ProjectStatus
    {
        return $this->status;
    }

    /**
     * @return list<ProjectDomainEvent>
     */
    public function events(): array
    {
        return $this->events;
    }
}
