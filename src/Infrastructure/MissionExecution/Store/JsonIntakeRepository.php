<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionExecution\Store;

use Aep\Application\MissionExecution\Model\MissionIntake;
use Aep\Application\MissionExecution\Port\IntakeRepository;

final class JsonIntakeRepository implements IntakeRepository
{
    public function __construct(private string $directory)
    {
        $this->directory = rtrim($directory, "/\\");
    }

    public function save(MissionIntake $intake): void
    {
        $this->ensureDir();
        $this->write($this->path($intake->id()), $intake->toArray());
        if ($intake->clientRequestId() !== null && $intake->clientRequestId() !== '') {
            $this->write($this->directory . '/by-client/' . $this->safe($intake->clientRequestId()) . '.json', [
                'intakeId' => $intake->id(),
            ]);
        }
        if ($intake->missionId() !== null) {
            $this->write($this->directory . '/by-mission/' . $this->safe($intake->missionId()) . '.json', [
                'intakeId' => $intake->id(),
            ]);
        }
    }

    public function find(string $id): ?MissionIntake
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }
        $data = $this->read($path);

        return MissionIntake::fromArray($data);
    }

    public function findByClientRequestId(string $clientRequestId): ?MissionIntake
    {
        $map = $this->directory . '/by-client/' . $this->safe($clientRequestId) . '.json';
        if (!is_file($map)) {
            return null;
        }
        $data = $this->read($map);
        $id = is_string($data['intakeId'] ?? null) ? $data['intakeId'] : '';

        return $id === '' ? null : $this->find($id);
    }

    public function findByMissionId(string $missionId): ?MissionIntake
    {
        $map = $this->directory . '/by-mission/' . $this->safe($missionId) . '.json';
        if (!is_file($map)) {
            return null;
        }
        $data = $this->read($map);
        $id = is_string($data['intakeId'] ?? null) ? $data['intakeId'] : '';

        return $id === '' ? null : $this->find($id);
    }

    private function path(string $id): string
    {
        return $this->directory . '/' . $this->safe($id) . '.json';
    }

    private function ensureDir(): void
    {
        foreach ([$this->directory, $this->directory . '/by-client', $this->directory . '/by-mission'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create intake directory: ' . $dir);
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
