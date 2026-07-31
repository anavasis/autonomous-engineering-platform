<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Planning\Store;

use Aep\Application\Planning\Model\PlanningEvent;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramSnapshot;
use Aep\Application\Planning\Port\ProgramStore;

final class FilesystemProgramStore implements ProgramStore
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, "/\\");
        foreach ([
            $this->root,
            $this->root . '/programs',
            $this->root . '/index/by-status',
            $this->root . '/index/by-project',
            $this->root . '/index/by-mission',
            $this->root . '/queue',
        ] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create planning store: ' . $dir);
            }
        }
    }

    public function save(Program $program): void
    {
        $dir = $this->programDir($program->programId());
        foreach ([$dir, $dir . '/snapshots'] as $d) {
            if (!is_dir($d) && !mkdir($d, 0775, true) && !is_dir($d)) {
                throw new \RuntimeException('Unable to create program dir.');
            }
        }
        $data = $program->toArray();
        file_put_contents($dir . '/program.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($dir . '/graph.json', json_encode($program->graph()->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($dir . '/schedule.json', json_encode($program->schedule(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($dir . '/allocations.json', json_encode($program->allocations(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        if (!is_file($dir . '/timeline.jsonl')) {
            file_put_contents($dir . '/timeline.jsonl', '');
        }
        $index = json_encode([
            'programId' => $program->programId(),
            'status' => $program->status(),
            'projectId' => $program->projectId(),
            'updatedAtUtc' => $program->updatedAtUtc(),
            'title' => $program->title(),
        ], JSON_THROW_ON_ERROR);
        file_put_contents($this->root . '/index/by-status/' . $this->safe($program->status()) . '__' . $this->safe($program->programId()) . '.json', $index);
        if ($program->projectId()) {
            file_put_contents(
                $this->root . '/index/by-project/' . $this->safe($program->projectId()) . '__' . $this->safe($program->programId()) . '.json',
                $index
            );
        }
    }

    public function find(string $programId): ?Program
    {
        $path = $this->programDir($programId) . '/program.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? Program::fromArray($data) : null;
    }

    public function list(?string $status = null, ?string $projectId = null): array
    {
        $out = [];
        foreach (glob($this->root . '/programs/*/program.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $program = Program::fromArray($data);
            if ($status !== null && $status !== '' && $program->status() !== $status) {
                continue;
            }
            if ($projectId !== null && $projectId !== '' && $program->projectId() !== $projectId) {
                continue;
            }
            $out[] = $program;
        }
        usort($out, static fn (Program $a, Program $b): int => strcmp($b->updatedAtUtc(), $a->updatedAtUtc()));

        return $out;
    }

    public function appendEvent(PlanningEvent $event): void
    {
        $dir = $this->programDir($event->programId());
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create program dir for event.');
        }
        file_put_contents(
            $dir . '/timeline.jsonl',
            json_encode($event->toArray(), JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND
        );
    }

    public function events(string $programId, int $limit = 200): array
    {
        $path = $this->programDir($programId) . '/timeline.jsonl';
        if (!is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $items = [];
        foreach (array_reverse($lines) as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                $items[] = PlanningEvent::fromArray($data);
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function saveSnapshot(ProgramSnapshot $snapshot): void
    {
        $dir = $this->programDir($snapshot->programId()) . '/snapshots';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create snapshots dir.');
        }
        $snap = $snapshot->withIntegrity();
        file_put_contents(
            $dir . '/' . $this->safe($snap->snapshotId()) . '.json',
            json_encode($snap->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );
    }

    public function snapshots(string $programId): array
    {
        $dir = $this->programDir($programId) . '/snapshots';
        $out = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $out[] = ProgramSnapshot::fromArray($data);
            }
        }
        usort($out, static fn (ProgramSnapshot $a, ProgramSnapshot $b): int => $b->sequence() <=> $a->sequence());

        return $out;
    }

    public function latestSnapshot(string $programId): ?ProgramSnapshot
    {
        $all = $this->snapshots($programId);

        return $all[0] ?? null;
    }

    public function indexMission(string $missionId, string $programId): void
    {
        file_put_contents(
            $this->root . '/index/by-mission/' . $this->safe($missionId) . '.json',
            json_encode(['missionId' => $missionId, 'programId' => $programId], JSON_THROW_ON_ERROR)
        );
    }

    public function findProgramIdByMission(string $missionId): ?string
    {
        $path = $this->root . '/index/by-mission/' . $this->safe($missionId) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && is_string($data['programId'] ?? null) ? $data['programId'] : null;
    }

    private function programDir(string $programId): string
    {
        return $this->root . '/programs/' . $this->safe($programId);
    }

    private function safe(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $id) ?: 'unknown';
    }
}
