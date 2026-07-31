<?php

declare(strict_types=1);

namespace Aep\Domain\Project;

use Aep\Domain\Mission\ValueObject\ActorRef;
use Aep\Domain\Project\ValueObject\ProjectId;

/**
 * ORCH-R7a Project domain events.
 */
abstract class ProjectDomainEvent
{
    public function __construct(
        private ProjectId $projectId,
        private string $occurredAtUtc
    ) {
    }

    public function projectId(): ProjectId
    {
        return $this->projectId;
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAtUtc;
    }

    abstract public function eventName(): string;
}

final class ProjectCreated extends ProjectDomainEvent
{
    public function __construct(
        ProjectId $projectId,
        string $occurredAtUtc,
        private ActorRef $createdBy
    ) {
        parent::__construct($projectId, $occurredAtUtc);
    }

    public function createdBy(): ActorRef
    {
        return $this->createdBy;
    }

    public function eventName(): string
    {
        return 'ProjectCreated';
    }
}

final class EnvironmentAdded extends ProjectDomainEvent
{
    public function eventName(): string
    {
        return 'EnvironmentAdded';
    }
}

final class ServerDefinitionAdded extends ProjectDomainEvent
{
    public function eventName(): string
    {
        return 'ServerDefinitionAdded';
    }
}

final class RepositoryBound extends ProjectDomainEvent
{
    public function eventName(): string
    {
        return 'RepositoryBound';
    }
}

final class ProjectArchived extends ProjectDomainEvent
{
    public function eventName(): string
    {
        return 'ProjectArchived';
    }
}
