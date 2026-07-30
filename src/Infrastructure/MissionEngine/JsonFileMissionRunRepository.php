<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionEngine;

use Aep\Application\MissionEngine\MissionCheckpoint;
use Aep\Application\MissionEngine\MissionRunRepository;
use Aep\Application\MissionEngine\MissionTimeline;

/**
 * JSON-file persistence for MissionEngine checkpoints and timelines.
 *
 * Layout:
 *   {directory}/{runId}/checkpoint.json
 *   {directory}/{runId}/timeline.json
 */
final class JsonFileMissionRunRepository implements MissionRunRepository
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private string $directory
    ) {
        $directory = rtrim($directory, "/\\");
        if ($directory === '') {
            throw new \InvalidArgumentException('JsonFileMissionRunRepository directory must be non-empty.');
        }
        $this->directory = $directory;
    }

    public function save(MissionCheckpoint $checkpoint, MissionTimeline $timeline): void
    {
        $dir = $this->runDir($checkpoint->runId());
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create mission-run directory: ' . $dir);
        }

        $this->writeJson($dir . DIRECTORY_SEPARATOR . 'checkpoint.json', [
            'schemaVersion' => self::SCHEMA_VERSION,
            'checkpoint' => $checkpoint->toArray(),
        ]);
        $this->writeJson($dir . DIRECTORY_SEPARATOR . 'timeline.json', [
            'schemaVersion' => self::SCHEMA_VERSION,
            'entries' => $timeline->toArray(),
        ]);
    }

    public function getCheckpoint(string $runId): MissionCheckpoint
    {
        $path = $this->runDir($runId) . DIRECTORY_SEPARATOR . 'checkpoint.json';
        $data = $this->readJson($path);
        $checkpoint = $data['checkpoint'] ?? null;
        if (!is_array($checkpoint)) {
            throw new \RuntimeException('Invalid checkpoint payload for run ' . $runId);
        }

        return MissionCheckpoint::fromArray($checkpoint);
    }

    public function getTimeline(string $runId): MissionTimeline
    {
        $path = $this->runDir($runId) . DIRECTORY_SEPARATOR . 'timeline.json';
        $data = $this->readJson($path);
        $entries = $data['entries'] ?? null;
        if (!is_array($entries)) {
            throw new \RuntimeException('Invalid timeline payload for run ' . $runId);
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = [];
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $rows[] = $entry;
            }
        }

        return MissionTimeline::fromArray($rows);
    }

    public function exists(string $runId): bool
    {
        return is_file($this->runDir($runId) . DIRECTORY_SEPARATOR . 'checkpoint.json');
    }

    private function runDir(string $runId): string
    {
        $this->assertSafeRunId($runId);

        return $this->directory . DIRECTORY_SEPARATOR . $runId;
    }

    private function assertSafeRunId(string $runId): void
    {
        if ($runId === '' || preg_match('/[^A-Za-z0-9_-]/', $runId) === 1) {
            throw new \InvalidArgumentException('runId is not safe for filesystem persistence: ' . $runId);
        }
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        try {
            $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Unable to encode mission-run JSON: ' . $path, 0, $e);
        }

        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temp, $payload . "\n") === false) {
            throw new \RuntimeException('Unable to write temporary file: ' . $temp);
        }
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to atomically replace file: ' . $path);
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Mission run file not found: ' . $path);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read mission-run file: ' . $path);
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid mission-run JSON: ' . $path, 0, $e);
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Mission-run JSON must decode to an object: ' . $path);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
