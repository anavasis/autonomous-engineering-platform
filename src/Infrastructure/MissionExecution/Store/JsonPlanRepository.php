<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionExecution\Store;

use Aep\Application\MissionExecution\Model\ExecutionPlan;
use Aep\Application\MissionExecution\Port\PlanRepository;

final class JsonPlanRepository implements PlanRepository
{
    public function __construct(private string $directory)
    {
        $this->directory = rtrim($directory, "/\\");
    }

    public function save(ExecutionPlan $plan): void
    {
        $this->ensureDir();
        $data = $plan->toArray();
        $this->write($this->directory . '/' . $this->safe($plan->id()) . '.json', $data);
        $this->write($this->directory . '/by-intake/' . $this->safe($plan->intakeId()) . '.json', [
            'planId' => $plan->id(),
        ]);
        $missionId = $data['launchAttributes']['missionId'] ?? null;
        if (is_string($missionId) && $missionId !== '') {
            $this->write($this->directory . '/by-mission/' . $this->safe($missionId) . '.json', [
                'planId' => $plan->id(),
            ]);
        }
    }

    public function find(string $id): ?ExecutionPlan
    {
        $path = $this->directory . '/' . $this->safe($id) . '.json';
        if (!is_file($path)) {
            return null;
        }

        return ExecutionPlan::fromArray($this->read($path));
    }

    public function findByIntakeId(string $intakeId): ?ExecutionPlan
    {
        $map = $this->directory . '/by-intake/' . $this->safe($intakeId) . '.json';
        if (!is_file($map)) {
            return null;
        }
        $data = $this->read($map);
        $id = is_string($data['planId'] ?? null) ? $data['planId'] : '';

        return $id === '' ? null : $this->find($id);
    }

    public function findByMissionId(string $missionId): ?ExecutionPlan
    {
        $map = $this->directory . '/by-mission/' . $this->safe($missionId) . '.json';
        if (!is_file($map)) {
            return null;
        }
        $data = $this->read($map);
        $id = is_string($data['planId'] ?? null) ? $data['planId'] : '';

        return $id === '' ? null : $this->find($id);
    }

    private function ensureDir(): void
    {
        foreach ([$this->directory, $this->directory . '/by-intake', $this->directory . '/by-mission'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create plan directory.');
            }
        }
    }

    private function safe(string $id): string
    {
        if ($id === '' || preg_match('/[^A-Za-z0-9_-]/', $id) === 1) {
            throw new \InvalidArgumentException('Unsafe id: ' . $id);
        }

        return $id;
    }

    /** @param array<string, mixed> $data */
    private function write(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create directory: ' . $dir);
        }
        $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temp, $payload . "\n") === false) {
            throw new \RuntimeException('Unable to write ' . $path);
        }
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to replace ' . $path);
        }
    }

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read ' . $path);
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON: ' . $path);
        }

        return $data;
    }
}
