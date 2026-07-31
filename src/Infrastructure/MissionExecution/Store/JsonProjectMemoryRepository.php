<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionExecution\Store;

use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\MissionExecution\Model\ProjectMemory;
use Aep\Application\MissionExecution\Port\ProjectMemoryRepository;

final class JsonProjectMemoryRepository implements ProjectMemoryRepository
{
    public function __construct(private string $directory)
    {
        $this->directory = rtrim($directory, "/\\");
    }

    public function save(ProjectMemory $memory): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create memory directory.');
        }
        $path = $this->directory . '/' . $this->safe($memory->projectId()) . '.json';
        $payload = json_encode($memory->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temp, $payload . "\n") === false) {
            throw new \RuntimeException('Unable to write memory file.');
        }
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to replace memory file.');
        }
    }

    public function find(string $projectId): ?ProjectMemory
    {
        $path = $this->directory . '/' . $this->safe($projectId) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            return null;
        }

        return ProjectMemory::fromArray($data);
    }

    public function getOrCreate(string $projectId): ProjectMemory
    {
        $existing = $this->find($projectId);
        if ($existing !== null) {
            return $existing;
        }
        $memory = new ProjectMemory($projectId, [], ['src/'], [], [], [], [], [], Utc::now());
        $this->save($memory);

        return $memory;
    }

    private function safe(string $id): string
    {
        if ($id === '' || preg_match('/[^A-Za-z0-9_-]/', $id) === 1) {
            throw new \InvalidArgumentException('Unsafe projectId: ' . $id);
        }

        return $id;
    }
}
