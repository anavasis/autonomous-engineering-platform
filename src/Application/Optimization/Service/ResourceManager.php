<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Service;

use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\Optimization\Model\Resource;
use Aep\Application\Optimization\Port\OptimizationStore;

final class ResourceManager
{
    public function __construct(private readonly OptimizationStore $store) {}

    /** @param array<string, mixed> $config */
    public function seedFromConfig(array $config): void
    {
        $resources = is_array($config['resources'] ?? null) ? $config['resources'] : [];
        $at = Utc::now();
        foreach ($resources as $row) {
            if (!is_array($row)) { continue; }
            $id = is_string($row['id'] ?? null) ? $row['id'] : '';
            if ($id === '' || $this->store->findResource($id) !== null) { continue; }
            $this->store->saveResource(new Resource(
                $id,
                is_string($row['type'] ?? null) ? $row['type'] : 'generic',
                is_string($row['scope'] ?? null) ? $row['scope'] : 'global',
                is_string($row['unit'] ?? null) ? $row['unit'] : 'units',
                is_array($row['limits'] ?? null) ? $row['limits'] : [],
                is_array($row['tags'] ?? null) ? $row['tags'] : [],
                $at,
            ));
        }
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map(static fn (Resource $r) => $r->toArray(), $this->store->listResources());
    }

    /** @return array<string, mixed>|null */
    public function get(string $resourceId): ?array
    {
        return $this->store->findResource($resourceId)?->toArray();
    }
}
