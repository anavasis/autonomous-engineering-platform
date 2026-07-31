<?php

declare(strict_types=1);

namespace Aep\Infrastructure\CodeReview\Store;

use Aep\Application\CodeReview\Model\Patch;
use Aep\Application\CodeReview\Port\PatchStore;

final class FilesystemPatchStore implements PatchStore
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, "/\\");
        foreach ([
            $this->root,
            $this->root . '/index/by-mission',
            $this->root . '/index/by-run',
        ] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create patch store: ' . $dir);
            }
        }
    }

    public function save(Patch $patch): void
    {
        $dir = $this->root . '/' . $this->safe($patch->patchId());
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create patch dir.');
        }
        file_put_contents($dir . '/patch.json', json_encode($patch->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($dir . '/manifest.json', json_encode($patch->manifest()->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($dir . '/diff.unified', $patch->diffText());
        $checksDir = $dir . '/checks';
        if (!is_dir($checksDir)) {
            mkdir($checksDir, 0775, true);
        }
        foreach ($patch->checks() as $check) {
            $data = $check->toArray();
            file_put_contents(
                $checksDir . '/' . $this->safe($data['checkId']) . '.json',
                json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
            );
        }
        $reviewsDir = $dir . '/reviews';
        if (!is_dir($reviewsDir)) {
            mkdir($reviewsDir, 0775, true);
        }
        foreach ($patch->reviews() as $review) {
            $data = $review->toArray();
            file_put_contents(
                $reviewsDir . '/' . $this->safe($data['reviewId']) . '.json',
                json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
            );
        }
        $payload = json_encode([
            'patchId' => $patch->patchId(),
            'missionId' => $patch->missionId(),
            'runId' => $patch->runId(),
            'status' => $patch->status(),
            'updatedAtUtc' => $patch->toArray()['updatedAtUtc'] ?? '',
            'mergeReady' => $patch->mergeReadiness()->ready(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        file_put_contents(
            $this->root . '/index/by-run/' . $this->safe($patch->missionId()) . '__' . $this->safe($patch->runId()) . '.json',
            $payload
        );
        file_put_contents(
            $this->root . '/index/by-mission/' . $this->safe($patch->missionId()) . '.json',
            $payload
        );
    }

    public function find(string $patchId): ?Patch
    {
        $dir = $this->root . '/' . $this->safe($patchId);
        $path = $dir . '/patch.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }
        $diff = is_file($dir . '/diff.unified') ? (string) file_get_contents($dir . '/diff.unified') : '';

        return Patch::fromArray($data, $diff);
    }

    public function findActiveByRun(string $missionId, string $runId): ?Patch
    {
        $path = $this->root . '/index/by-run/' . $this->safe($missionId) . '__' . $this->safe($runId) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        $id = is_array($data) && is_string($data['patchId'] ?? null) ? $data['patchId'] : null;

        return $id !== null ? $this->find($id) : null;
    }

    public function list(?string $missionId = null, ?string $status = null, ?bool $mergeReady = null): array
    {
        $out = [];
        foreach (glob($this->root . '/patch_*', GLOB_ONLYDIR) ?: [] as $dir) {
            $patch = $this->find(basename($dir));
            if ($patch === null) {
                continue;
            }
            if ($missionId !== null && $patch->missionId() !== $missionId) {
                continue;
            }
            if ($status !== null && $patch->status() !== $status) {
                continue;
            }
            if ($mergeReady !== null && $patch->mergeReadiness()->ready() !== $mergeReady) {
                continue;
            }
            $out[] = $patch;
        }

        return $out;
    }

    public function appendTimeline(string $patchId, array $event): void
    {
        $path = $this->root . '/' . $this->safe($patchId) . '/timeline.jsonl';
        $parent = dirname($path);
        if (!is_dir($parent)) {
            mkdir($parent, 0775, true);
        }
        file_put_contents($path, json_encode($event, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    }

    public function timeline(string $patchId): array
    {
        $path = $this->root . '/' . $this->safe($patchId) . '/timeline.jsonl';
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function safe(string $id): string
    {
        if ($id === '' || preg_match('/[^A-Za-z0-9_.-]/', $id) === 1) {
            throw new \InvalidArgumentException('Unsafe id.');
        }

        return $id;
    }
}
