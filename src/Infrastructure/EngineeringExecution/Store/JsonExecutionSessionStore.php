<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringExecution\Store;

use Aep\Application\EngineeringExecution\Model\ExecutionSession;
use Aep\Application\EngineeringExecution\Model\ProviderEvent;
use Aep\Application\EngineeringExecution\Port\ExecutionSessionStore;

final class JsonExecutionSessionStore implements ExecutionSessionStore
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, "/\\");
        foreach ([$this->root, $this->root . '/sessions', $this->root . '/events'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create execution store dir: ' . $dir);
            }
        }
    }

    public function save(ExecutionSession $session): void
    {
        $path = $this->root . '/sessions/' . $session->sessionId() . '.json';
        file_put_contents(
            $path,
            json_encode($session->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );
        $indexPath = $this->root . '/mission-index.json';
        $index = $this->readJson($indexPath);
        $index[$session->missionId()] = $session->sessionId();
        file_put_contents($indexPath, json_encode($index, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    public function find(string $sessionId): ?ExecutionSession
    {
        $path = $this->root . '/sessions/' . $sessionId . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }

        return ExecutionSession::fromArray($data);
    }

    public function findLatestForMission(string $missionId): ?ExecutionSession
    {
        $index = $this->readJson($this->root . '/mission-index.json');
        $sessionId = $index[$missionId] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            return null;
        }

        return $this->find($sessionId);
    }

    public function appendEvent(string $sessionId, ProviderEvent $event): void
    {
        $path = $this->root . '/events/' . $sessionId . '.json';
        $events = $this->readJsonList($path);
        $events[] = $event->toArray();
        file_put_contents($path, json_encode($events, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    public function events(string $sessionId, int $afterSeq = 0): array
    {
        $path = $this->root . '/events/' . $sessionId . '.json';
        $out = [];
        foreach ($this->readJsonList($path) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $event = ProviderEvent::fromArray($row);
            if ($event->seq() > $afterSeq) {
                $out[] = $event;
            }
        }

        return $out;
    }

    public function appendAudit(array $entry): void
    {
        $path = $this->root . '/audit.jsonl';
        file_put_contents(
            $path,
            json_encode($entry, JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND
        );
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /** @return list<mixed> */
    private function readJsonList(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }

        return array_values($data);
    }
}
