<?php

declare(strict_types=1);

namespace Aep\Domain\Project;

use Aep\Domain\Mission\ValueObject\ActorRef;
use Aep\Domain\Project\Entity\Environment;
use Aep\Domain\Project\Entity\ServerDefinition;
use Aep\Domain\Project\ValueObject\ProjectId;
use Aep\Domain\Project\ValueObject\ProjectSlug;
use Aep\Domain\Project\ValueObject\ProjectStatus;
use Aep\Domain\Project\ValueObject\RepositoryBinding;

require_once __DIR__ . '/ProjectEvents.php';


/**
 * ORCH-R7a Project aggregate root (Project Registry).
 *
 * TODO(ORCH-future): Git adapter integration via RepositoryBinding.
 * TODO(ORCH-future): SSH/Deploy adapters via ServerDefinition + SecretRef.
 * TODO(ORCH-future): Marketplace ExtensionConfig.
 * TODO(ORCH-future): Permissions / multi-user membership.
 * TODO(ORCH-future): Mission aggregate binding (R7b).
 */
final class Project
{
    /** @var list<ProjectDomainEvent> */
    private array $recordedEvents = [];

    /** @var array<string, Environment> */
    private array $environments = [];

    /** @var array<string, ServerDefinition> */
    private array $servers = [];

    private ProjectStatus $status;
    private string $displayName;
    private string $description;
    private string $updatedAtUtc;
    private ?RepositoryBinding $repositoryBinding = null;

    /** Placeholder metrics for future dashboard — no calculations in R7a. */
    private int $missionCount = 0;
    private int $deploymentCount = 0;
    private ?string $lastDeploymentAt = null;

    private function __construct(
        private ProjectId $id,
        private ProjectSlug $slug,
        private ActorRef $createdBy,
        private string $createdAtUtc,
        string $displayName,
        string $description = ''
    ) {
        $this->displayName = self::requireDisplayName($displayName);
        $this->description = trim($description);
        $this->status = ProjectStatus::active();
        $this->updatedAtUtc = $createdAtUtc;
    }

    public static function create(
        ProjectId $id,
        ProjectSlug $slug,
        string $displayName,
        ActorRef $createdBy,
        string $createdAtUtc,
        string $description = ''
    ): self {
        $createdAtUtc = self::requireUtc($createdAtUtc, 'createdAtUtc');
        $project = new self($id, $slug, $createdBy, $createdAtUtc, $displayName, $description);
        $project->record(new ProjectCreated($id, $createdAtUtc, $createdBy));

        return $project;
    }

    /**
     * Restore from persistence snapshot. No domain events.
     *
     * @param list<Environment> $environments
     * @param list<ServerDefinition> $servers
     */
    public static function reconstitute(
        ProjectId $id,
        ProjectSlug $slug,
        ActorRef $createdBy,
        string $createdAtUtc,
        string $displayName,
        string $description,
        ProjectStatus $status,
        string $updatedAtUtc,
        array $environments = [],
        array $servers = [],
        ?RepositoryBinding $repositoryBinding = null,
        int $missionCount = 0,
        int $deploymentCount = 0,
        ?string $lastDeploymentAt = null
    ): self {
        $createdAtUtc = self::requireUtc($createdAtUtc, 'createdAtUtc');
        $updatedAtUtc = self::requireUtc($updatedAtUtc, 'updatedAtUtc');
        $project = new self($id, $slug, $createdBy, $createdAtUtc, $displayName, $description);
        $project->status = $status;
        $project->updatedAtUtc = $updatedAtUtc;
        $project->repositoryBinding = $repositoryBinding;
        $project->missionCount = max(0, $missionCount);
        $project->deploymentCount = max(0, $deploymentCount);
        $project->lastDeploymentAt = $lastDeploymentAt;
        $project->recordedEvents = [];

        foreach ($environments as $environment) {
            if (!$environment instanceof Environment) {
                throw new \InvalidArgumentException('reconstitute environments must be Environment instances.');
            }
            $project->environments[$environment->id()->toString()] = $environment;
        }
        foreach ($servers as $server) {
            if (!$server instanceof ServerDefinition) {
                throw new \InvalidArgumentException('reconstitute servers must be ServerDefinition instances.');
            }
            $project->servers[$server->id()->toString()] = $server;
        }

        return $project;
    }

    public function id(): ProjectId
    {
        return $this->id;
    }

    public function slug(): ProjectSlug
    {
        return $this->slug;
    }

    public function createdBy(): ActorRef
    {
        return $this->createdBy;
    }

    public function createdAtUtc(): string
    {
        return $this->createdAtUtc;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function status(): ProjectStatus
    {
        return $this->status;
    }

    public function updatedAtUtc(): string
    {
        return $this->updatedAtUtc;
    }

    public function repositoryBinding(): ?RepositoryBinding
    {
        return $this->repositoryBinding;
    }

    /**
     * @return list<Environment>
     */
    public function environments(): array
    {
        return array_values($this->environments);
    }

    /**
     * @return list<ServerDefinition>
     */
    public function servers(): array
    {
        return array_values($this->servers);
    }

    /** Dashboard placeholder — no calculations. */
    public function missionCount(): int
    {
        return $this->missionCount;
    }

    /** Dashboard placeholder — no calculations. */
    public function deploymentCount(): int
    {
        return $this->deploymentCount;
    }

    /** Dashboard placeholder — no calculations. */
    public function lastDeploymentAt(): ?string
    {
        return $this->lastDeploymentAt;
    }

    /**
     * @return list<ProjectDomainEvent>
     */
    public function recordedEvents(): array
    {
        return $this->recordedEvents;
    }

    /**
     * @return list<ProjectDomainEvent>
     */
    public function pullRecordedEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    public function addEnvironment(Environment $environment, string $occurredAtUtc): void
    {
        $this->assertMutable($occurredAtUtc);
        $key = $environment->id()->toString();
        if (isset($this->environments[$key])) {
            throw $this->illegal('addEnvironment', 'EnvironmentId already exists: ' . $key);
        }
        foreach ($this->environments as $existing) {
            if (strcasecmp($existing->name(), $environment->name()) === 0) {
                throw $this->illegal('addEnvironment', 'Environment name already exists: ' . $environment->name());
            }
        }
        $this->environments[$key] = $environment;
        $this->touch($occurredAtUtc);
        $this->record(new EnvironmentAdded($this->id, $occurredAtUtc));
    }

    public function addServerDefinition(ServerDefinition $server, string $occurredAtUtc): void
    {
        $this->assertMutable($occurredAtUtc);
        $key = $server->id()->toString();
        if (isset($this->servers[$key])) {
            throw $this->illegal('addServerDefinition', 'ServerDefinitionId already exists: ' . $key);
        }
        $this->servers[$key] = $server;
        $this->touch($occurredAtUtc);
        $this->record(new ServerDefinitionAdded($this->id, $occurredAtUtc));
    }

    public function bindRepository(RepositoryBinding $binding, string $occurredAtUtc): void
    {
        $this->assertMutable($occurredAtUtc);
        if ($this->repositoryBinding !== null) {
            throw $this->illegal('bindRepository', 'Project already has exactly one repository binding.');
        }
        $this->repositoryBinding = $binding;
        $this->touch($occurredAtUtc);
        $this->record(new RepositoryBound($this->id, $occurredAtUtc));
    }

    public function archive(string $occurredAtUtc): void
    {
        $occurredAtUtc = self::requireUtc($occurredAtUtc, 'occurredAtUtc');
        if ($this->status->isArchived()) {
            throw $this->illegal('archive', 'Project is already archived.');
        }
        $this->status = ProjectStatus::archived();
        $this->touch($occurredAtUtc);
        $this->record(new ProjectArchived($this->id, $occurredAtUtc));
    }

    private function assertMutable(string $occurredAtUtc): void
    {
        $occurredAtUtc = self::requireUtc($occurredAtUtc, 'occurredAtUtc');
        if ($this->status->isArchived()) {
            throw $this->illegal('mutate', 'Archived projects cannot be modified.');
        }
        unset($occurredAtUtc);
    }

    private function touch(string $occurredAtUtc): void
    {
        $this->updatedAtUtc = self::requireUtc($occurredAtUtc, 'occurredAtUtc');
    }

    private function record(ProjectDomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    private function illegal(string $action, string $reason): \DomainException
    {
        return new \DomainException('Project ' . $this->id->toString() . ' rejected "' . $action . '": ' . $reason);
    }

    private static function requireDisplayName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('displayName must be non-empty.');
        }

        return $value;
    }

    private static function requireUtc(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' must be non-empty UTC timestamp string.');
        }

        return $value;
    }
}
