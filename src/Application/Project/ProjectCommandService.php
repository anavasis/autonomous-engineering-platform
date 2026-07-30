<?php

declare(strict_types=1);

namespace Aep\Application\Project;

use Aep\Application\Project\Command\AddEnvironment;
use Aep\Application\Project\Command\AddServerDefinition;
use Aep\Application\Project\Command\ArchiveProject;
use Aep\Application\Project\Command\BindRepository;
use Aep\Application\Project\Command\CreateProject;
use Aep\Domain\Mission\ValueObject\ActorRef;
use Aep\Domain\Project\Entity\Environment;
use Aep\Domain\Project\Entity\ServerDefinition;
use Aep\Domain\Project\Project;
use Aep\Domain\Project\ProjectRepository;
use Aep\Domain\Project\ValueObject\AccessMethod;
use Aep\Domain\Project\ValueObject\EnvironmentId;
use Aep\Domain\Project\ValueObject\EnvironmentKind;
use Aep\Domain\Project\ValueObject\HostRef;
use Aep\Domain\Project\ValueObject\ProjectId;
use Aep\Domain\Project\ValueObject\ProjectSlug;
use Aep\Domain\Project\ValueObject\RepositoryBinding;
use Aep\Domain\Project\ValueObject\SecretRef;
use Aep\Domain\Project\ValueObject\ServerDefinitionId;

final class ProjectCommandService
{
    public function __construct(
        private ProjectRepository $projects
    ) {
    }

    public function create(CreateProject $command): ProjectResult
    {
        $id = new ProjectId($command->projectId());
        if ($this->projects->exists($id)) {
            throw new \RuntimeException('Project already exists: ' . $id->toString());
        }

        $project = Project::create(
            $id,
            new ProjectSlug($command->slug()),
            $command->displayName(),
            new ActorRef($command->actorType(), $command->actorId()),
            $command->occurredAtUtc(),
            $command->description()
        );

        return $this->persist($project);
    }

    public function addEnvironment(AddEnvironment $command): ProjectResult
    {
        $project = $this->load($command->projectId());
        $project->addEnvironment(
            new Environment(
                new EnvironmentId($command->environmentId()),
                $command->name(),
                new EnvironmentKind($command->kind())
            ),
            $command->occurredAtUtc()
        );

        return $this->persist($project);
    }

    public function addServerDefinition(AddServerDefinition $command): ProjectResult
    {
        $project = $this->load($command->projectId());
        $project->addServerDefinition(
            new ServerDefinition(
                new ServerDefinitionId($command->serverId()),
                $command->label(),
                new HostRef($command->host()),
                new AccessMethod($command->accessMethod()),
                $this->optionalSecret(
                    $command->secretVault(),
                    $command->secretKey(),
                    $command->secretVersion()
                )
            ),
            $command->occurredAtUtc()
        );

        return $this->persist($project);
    }

    public function bindRepository(BindRepository $command): ProjectResult
    {
        $project = $this->load($command->projectId());
        $project->bindRepository(
            new RepositoryBinding(
                $command->provider(),
                $command->repository(),
                $this->optionalSecret(
                    $command->secretVault(),
                    $command->secretKey(),
                    $command->secretVersion()
                )
            ),
            $command->occurredAtUtc()
        );

        return $this->persist($project);
    }

    public function archive(ArchiveProject $command): ProjectResult
    {
        $project = $this->load($command->projectId());
        $project->archive($command->occurredAtUtc());

        return $this->persist($project);
    }

    private function load(string $projectId): Project
    {
        return $this->projects->get(new ProjectId($projectId));
    }

    private function persist(Project $project): ProjectResult
    {
        $this->projects->save($project);
        $events = $project->pullRecordedEvents();

        return new ProjectResult($project->id(), $project->status(), $events);
    }

    private function optionalSecret(?string $vault, ?string $key, ?string $version): ?SecretRef
    {
        if ($vault === null && $key === null) {
            return null;
        }
        if ($vault === null || $key === null) {
            throw new \InvalidArgumentException('secretVault and secretKey must both be provided.');
        }

        return new SecretRef($vault, $key, $version);
    }
}
