<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Knowledge\Store;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Port\KnowledgeStore;

final class FilesystemKnowledgeStore implements KnowledgeStore
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, "/\\");
        foreach ([
            $this->root,
            $this->root . '/records',
            $this->root . '/embeddings',
            $this->root . '/index/by-project',
            $this->root . '/index/by-mission',
            $this->root . '/index/by-kind',
            $this->root . '/index/by-stable-key',
            $this->root . '/graph',
            $this->root . '/archive',
        ] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create knowledge store: ' . $dir);
            }
        }
        $timeline = $this->root . '/index/timeline.jsonl';
        if (!is_file($timeline)) {
            file_put_contents($timeline, '');
        }
        $edges = $this->root . '/graph/edges.jsonl';
        if (!is_file($edges)) {
            file_put_contents($edges, '');
        }
    }

    public function save(KnowledgeRecord $record): void
    {
        $dir = $this->root . '/records/' . $this->safe($record->knowledgeId());
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create knowledge record dir.');
        }
        $data = $record->toArray();
        file_put_contents($dir . '/record.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($dir . '/body.md', $record->body());
        file_put_contents($dir . '/links.json', json_encode($data['links'] ?? [], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $indexPayload = json_encode([
            'knowledgeId' => $record->knowledgeId(),
            'kind' => $record->kind(),
            'status' => $record->status(),
            'projectId' => $record->projectId(),
            'missionId' => $record->missionId(),
            'title' => $record->title(),
            'updatedAtUtc' => $record->updatedAtUtc(),
        ], JSON_THROW_ON_ERROR);

        if ($record->projectId()) {
            $this->appendIndex($this->root . '/index/by-project/' . $this->safe($record->projectId()) . '.jsonl', $record->knowledgeId(), $indexPayload);
        }
        if ($record->missionId()) {
            $this->appendIndex($this->root . '/index/by-mission/' . $this->safe($record->missionId()) . '.jsonl', $record->knowledgeId(), $indexPayload);
        }
        $this->appendIndex($this->root . '/index/by-kind/' . $this->safe($record->kind()) . '.jsonl', $record->knowledgeId(), $indexPayload);
        file_put_contents(
            $this->root . '/index/by-stable-key/' . $this->safe($record->stableKey()) . '.json',
            json_encode(['knowledgeId' => $record->knowledgeId(), 'stableKey' => $record->stableKey()], JSON_THROW_ON_ERROR)
        );

        if (in_array($record->status(), [KnowledgeRecord::STATUS_ARCHIVED, KnowledgeRecord::STATUS_FORGOTTEN], true)) {
            $archiveDir = $this->root . '/archive/' . $this->safe($record->knowledgeId());
            if (!is_dir($archiveDir)) {
                mkdir($archiveDir, 0775, true);
            }
            file_put_contents($archiveDir . '/record.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }

    public function find(string $knowledgeId): ?KnowledgeRecord
    {
        $path = $this->root . '/records/' . $this->safe($knowledgeId) . '/record.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? KnowledgeRecord::fromArray($data) : null;
    }

    public function findByStableKey(string $stableKey): ?KnowledgeRecord
    {
        $path = $this->root . '/index/by-stable-key/' . $this->safe($stableKey) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        $id = is_array($data) && is_string($data['knowledgeId'] ?? null) ? $data['knowledgeId'] : null;

        return $id !== null ? $this->find($id) : null;
    }

    public function list(?string $projectId = null, ?string $kind = null, ?string $status = null, ?string $q = null): array
    {
        $ids = [];
        if ($projectId !== null && $projectId !== '') {
            $ids = $this->idsFromJsonl($this->root . '/index/by-project/' . $this->safe($projectId) . '.jsonl');
        } elseif ($kind !== null && $kind !== '') {
            $ids = $this->idsFromJsonl($this->root . '/index/by-kind/' . $this->safe($kind) . '.jsonl');
        } else {
            foreach (glob($this->root . '/records/*/record.json') ?: [] as $file) {
                $ids[] = basename(dirname($file));
            }
        }

        $out = [];
        $seen = [];
        foreach ($ids as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $record = $this->find($id);
            if ($record === null) {
                continue;
            }
            if ($kind !== null && $kind !== '' && $record->kind() !== $kind) {
                continue;
            }
            if ($status !== null && $status !== '' && $record->status() !== $status) {
                continue;
            }
            if ($projectId !== null && $projectId !== '' && $record->projectId() !== $projectId && $record->scope() !== 'global') {
                continue;
            }
            if ($q !== null && $q !== '') {
                $hay = strtolower($record->title() . ' ' . $record->summary() . ' ' . $record->body());
                if (!str_contains($hay, strtolower($q))) {
                    continue;
                }
            }
            $out[] = $record;
        }

        usort($out, static fn (KnowledgeRecord $a, KnowledgeRecord $b): int => strcmp($b->updatedAtUtc(), $a->updatedAtUtc()));

        return $out;
    }

    public function listByMission(string $missionId): array
    {
        $ids = $this->idsFromJsonl($this->root . '/index/by-mission/' . $this->safe($missionId) . '.jsonl');
        $out = [];
        foreach (array_unique($ids) as $id) {
            $record = $this->find($id);
            if ($record !== null) {
                $out[] = $record;
            }
        }

        return $out;
    }

    public function timeline(?string $knowledgeId = null, int $limit = 100): array
    {
        $path = $this->root . '/index/timeline.jsonl';
        if (!is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $items = [];
        foreach (array_reverse($lines) as $line) {
            $data = json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }
            if ($knowledgeId !== null && ($data['knowledgeId'] ?? null) !== $knowledgeId) {
                continue;
            }
            $items[] = $data;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function appendTimeline(array $event): void
    {
        file_put_contents(
            $this->root . '/index/timeline.jsonl',
            json_encode($event, JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND
        );
    }

    public function addEdge(array $edge): void
    {
        file_put_contents(
            $this->root . '/graph/edges.jsonl',
            json_encode($edge, JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND
        );
    }

    public function edges(?string $nodeId = null, int $limit = 200): array
    {
        $path = $this->root . '/graph/edges.jsonl';
        if (!is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $items = [];
        foreach (array_reverse($lines) as $line) {
            $data = json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }
            if ($nodeId !== null) {
                $from = (string) ($data['fromId'] ?? '');
                $to = (string) ($data['toId'] ?? '');
                if ($from !== $nodeId && $to !== $nodeId) {
                    continue;
                }
            }
            $items[] = $data;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function saveEmbedding(string $providerId, string $knowledgeId, array $vector): void
    {
        $dir = $this->root . '/embeddings/' . $this->safe($providerId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create embedding dir.');
        }
        file_put_contents(
            $dir . '/' . $this->safe($knowledgeId) . '.vec.json',
            json_encode(['providerId' => $providerId, 'knowledgeId' => $knowledgeId, 'vector' => array_values($vector)], JSON_THROW_ON_ERROR)
        );
    }

    public function loadEmbedding(string $providerId, string $knowledgeId): ?array
    {
        $path = $this->root . '/embeddings/' . $this->safe($providerId) . '/' . $this->safe($knowledgeId) . '.vec.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['vector']) || !is_array($data['vector'])) {
            return null;
        }
        $out = [];
        foreach ($data['vector'] as $v) {
            if (is_numeric($v)) {
                $out[] = (float) $v;
            }
        }

        return $out;
    }

    private function appendIndex(string $path, string $knowledgeId, string $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // rewrite unique ids
        $ids = $this->idsFromJsonl($path);
        if (!in_array($knowledgeId, $ids, true)) {
            file_put_contents($path, $payload . "\n", FILE_APPEND);
        }
    }

    /** @return list<string> */
    private function idsFromJsonl(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $ids = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $data = json_decode($line, true);
            if (is_array($data) && is_string($data['knowledgeId'] ?? null)) {
                $ids[] = $data['knowledgeId'];
            }
        }

        return $ids;
    }

    private function safe(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $id) ?: 'unknown';
    }
}
