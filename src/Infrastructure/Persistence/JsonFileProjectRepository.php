<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Persistence;

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
use Aep\Domain\Project\ValueObject\ProjectStatus;
use Aep\Domain\Project\ValueObject\RepositoryBinding;
use Aep\Domain\Project\ValueObject\SecretRef;
use Aep\Domain\Project\ValueObject\ServerDefinitionId;

/**
 * Durable JSON-file ProjectRepository. Atomic upsert via temp file + rename.
 */
final class JsonFileProjectRepository implements ProjectRepository
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private string $directory
    ) {
        $directory = rtrim($directory, "/\\");
        if ($directory === '') {
            throw new \InvalidArgumentException('JsonFileProjectRepository directory must be non-empty.');
        }
        $this->directory = $directory;
    }

    public function get(ProjectId $id): Project
    {
        $path = $this->pathFor($id);
        if (!is_file($path)) {
            throw new \RuntimeException('Project not found: ' . $id->toString());
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read project file: ' . $path);
        }
        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid project JSON for ' . $id->toString(), 0, $e);
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Project JSON must decode to an object.');
        }

        return $this->fromSnapshot($data, $id);
    }

    public function save(Project $project): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create project storage directory: ' . $this->directory);
        }
        $path = $this->pathFor($project->id());
        $payload = json_encode($this->toSnapshot($project), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $temp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temp, $payload) === false) {
            throw new \RuntimeException('Unable to write temporary project file: ' . $temp);
        }
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to atomically replace project file: ' . $path);
        }
    }

    public function exists(ProjectId $id): bool
    {
        return is_file($this->pathFor($id));
    }

    private function pathFor(ProjectId $id): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $id->toString() . '.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function toSnapshot(Project $project): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'id' => $project->id()->toString(),
            'slug' => $project->slug()->toString(),
            'displayName' => $project->displayName(),
            'description' => $project->description(),
            'status' => $project->status()->toString(),
            'createdBy' => [
                'type' => $project->createdBy()->type(),
                'id' => $project->createdBy()->id(),
            ],
            'createdAtUtc' => $project->createdAtUtc(),
            'updatedAtUtc' => $project->updatedAtUtc(),
            'repositoryBinding' => $this->bindingToArray($project->repositoryBinding()),
            'environments' => array_map(
                static fn (Environment $e): array => [
                    'id' => $e->id()->toString(),
                    'name' => $e->name(),
                    'kind' => $e->kind()->toString(),
                ],
                $project->environments()
            ),
            'servers' => array_map(
                fn (ServerDefinition $s): array => [
                    'id' => $s->id()->toString(),
                    'label' => $s->label(),
                    'host' => $s->host()->toString(),
                    'accessMethod' => $s->accessMethod()->toString(),
                    'secretRef' => $this->secretToArray($s->secretRef()),
                ],
                $project->servers()
            ),
            'missionCount' => $project->missionCount(),
            'deploymentCount' => $project->deploymentCount(),
            'lastDeploymentAt' => $project->lastDeploymentAt(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fromSnapshot(array $data, ProjectId $expectedId): Project
    {
        if (($data['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('Unsupported project schemaVersion.');
        }
        $idValue = $this->reqString($data, 'id');
        if ($idValue !== $expectedId->toString()) {
            throw new \RuntimeException('Project file id mismatch.');
        }

        $environments = [];
        foreach ($data['environments'] ?? [] as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('Invalid environment row.');
            }
            $environments[] = new Environment(
                new EnvironmentId($this->reqString($row, 'id')),
                $this->reqString($row, 'name'),
                new EnvironmentKind($this->reqString($row, 'kind'))
            );
        }

        $servers = [];
        foreach ($data['servers'] ?? [] as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('Invalid server row.');
            }
            $servers[] = new ServerDefinition(
                new ServerDefinitionId($this->reqString($row, 'id')),
                $this->reqString($row, 'label'),
                new HostRef($this->reqString($row, 'host')),
                new AccessMethod($this->reqString($row, 'accessMethod')),
                $this->secretFromArray($row['secretRef'] ?? null)
            );
        }

        return Project::reconstitute(
            new ProjectId($idValue),
            new ProjectSlug($this->reqString($data, 'slug')),
            new ActorRef(
                $this->reqString($data['createdBy'] ?? [], 'type'),
                $this->reqString($data['createdBy'] ?? [], 'id')
            ),
            $this->reqString($data, 'createdAtUtc'),
            $this->reqString($data, 'displayName'),
            is_string($data['description'] ?? '') ? (string) $data['description'] : '',
            new ProjectStatus($this->reqString($data, 'status')),
            $this->reqString($data, 'updatedAtUtc'),
            $environments,
            $servers,
            $this->bindingFromArray($data['repositoryBinding'] ?? null),
            is_int($data['missionCount'] ?? 0) ? $data['missionCount'] : 0,
            is_int($data['deploymentCount'] ?? 0) ? $data['deploymentCount'] : 0,
            isset($data['lastDeploymentAt']) && is_string($data['lastDeploymentAt'])
                ? $data['lastDeploymentAt']
                : null
        );
    }

    /**
     * @return array{provider: string, repository: string, secretRef: mixed}|null
     */
    private function bindingToArray(?RepositoryBinding $binding): ?array
    {
        if ($binding === null) {
            return null;
        }

        return [
            'provider' => $binding->provider(),
            'repository' => $binding->repository(),
            'secretRef' => $this->secretToArray($binding->secretRef()),
        ];
    }

    private function bindingFromArray(mixed $data): ?RepositoryBinding
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data)) {
            throw new \RuntimeException('repositoryBinding must be an object or null.');
        }

        return new RepositoryBinding(
            $this->reqString($data, 'provider'),
            $this->reqString($data, 'repository'),
            $this->secretFromArray($data['secretRef'] ?? null)
        );
    }

    /**
     * @return array{vault: string, key: string, version: ?string}|null
     */
    private function secretToArray(?SecretRef $secret): ?array
    {
        if ($secret === null) {
            return null;
        }

        return [
            'vault' => $secret->vault(),
            'key' => $secret->key(),
            'version' => $secret->version(),
        ];
    }

    private function secretFromArray(mixed $data): ?SecretRef
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data)) {
            throw new \RuntimeException('secretRef must be an object or null.');
        }
        $version = $data['version'] ?? null;

        return new SecretRef(
            $this->reqString($data, 'vault'),
            $this->reqString($data, 'key'),
            is_string($version) ? $version : null
        );
    }

    /**
     * @param array<mixed> $data
     */
    private function reqString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException('Missing or invalid project snapshot field: ' . $key);
        }

        return $value;
    }
}
