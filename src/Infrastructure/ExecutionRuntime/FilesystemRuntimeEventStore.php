<?php

declare(strict_types=1);

namespace Aep\Infrastructure\ExecutionRuntime;

use Aep\Application\ExecutionRuntime\Model\RuntimeEvent;
use Aep\Application\ExecutionRuntime\Port\RuntimeEventStore;

/**
 * Append-only JSONL event log per job under {root}/events/{jobId}.jsonl
 */
final class FilesystemRuntimeEventStore implements RuntimeEventStore
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, "/\\") . '/events';
        if (!is_dir($this->root) && !mkdir($this->root, 0775, true) && !is_dir($this->root)) {
            throw new \RuntimeException('Unable to create runtime events dir: ' . $this->root);
        }
    }

    public function append(RuntimeEvent $event): void
    {
        $path = $this->pathFor($event->jobId());
        $line = json_encode($event->toArray(), JSON_THROW_ON_ERROR) . "\n";
        $written = file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Unable to append runtime event for job: ' . $event->jobId());
        }
    }

    public function forJob(string $jobId): array
    {
        $path = $this->pathFor($jobId);
        if (!is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        $events = [];
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }
            $events[] = RuntimeEvent::fromArray($data);
        }

        return $events;
    }

    private function pathFor(string $jobId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $jobId) ?? $jobId;

        return $this->root . '/' . $safe . '.jsonl';
    }
}
