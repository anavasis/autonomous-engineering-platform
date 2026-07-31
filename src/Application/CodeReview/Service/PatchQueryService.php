<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Service;

use Aep\Application\CodeReview\Port\PatchSettingsStore;
use Aep\Application\CodeReview\Port\ReviewProviderRegistry;

final class PatchQueryService
{
    public function __construct(
        private readonly PatchPipelineService $pipeline,
        private readonly PatchSettingsStore $settings,
        private readonly ReviewProviderRegistry $reviewProviders,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $missionId = null, ?string $status = null, ?bool $mergeReady = null): array
    {
        $items = [];
        foreach ($this->pipeline->list($missionId, $status, $mergeReady) as $patch) {
            $data = $patch->toArray();
            $items[] = [
                'patchId' => $data['patchId'],
                'missionId' => $data['missionId'],
                'runId' => $data['runId'],
                'status' => $data['status'],
                'score' => $data['score'],
                'grade' => $data['grade'],
                'mergeReady' => ($data['mergeReadiness']['ready'] ?? false) === true,
                'updatedAtUtc' => $data['updatedAtUtc'],
                'reproducibilityFingerprint' => $data['reproducibilityFingerprint'],
                'integrityHash' => $data['integrityHash'],
                'schemaVersion' => $data['schemaVersion'],
            ];
        }

        return $items;
    }

    /** @return array<string, mixed>|null */
    public function get(string $patchId): ?array
    {
        $patch = $this->pipeline->get($patchId);
        if ($patch === null) {
            return null;
        }
        $data = $patch->toArray();
        $data['timeline'] = $this->pipeline->timeline($patchId);

        return $data;
    }

    /** @return array<string, mixed>|null */
    public function diff(string $patchId): ?array
    {
        $patch = $this->pipeline->get($patchId);
        if ($patch === null) {
            return null;
        }

        return [
            'patchId' => $patchId,
            'diff' => $patch->diffText(),
            'diffHash' => $patch->manifest()->diffHash(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function manifest(string $patchId): ?array
    {
        $patch = $this->pipeline->get($patchId);
        if ($patch === null) {
            return null;
        }

        return ['patchId' => $patchId, 'manifest' => $patch->manifest()->toArray()];
    }

    /** @return list<array<string, mixed>> */
    public function checks(string $patchId): array
    {
        $patch = $this->pipeline->get($patchId);
        if ($patch === null) {
            return [];
        }

        return array_map(static fn ($c) => $c->toArray(), $patch->checks());
    }

    /** @return list<array<string, mixed>> */
    public function reviews(string $patchId): array
    {
        $patch = $this->pipeline->get($patchId);
        if ($patch === null) {
            return [];
        }

        return array_map(static fn ($r) => $r->toArray(), $patch->reviews());
    }

    /** @return list<array<string, mixed>> */
    public function timeline(string $patchId): array
    {
        return $this->pipeline->timeline($patchId);
    }

    /** @return array<string, mixed>|null */
    public function readiness(string $patchId): ?array
    {
        $patch = $this->pipeline->get($patchId);
        if ($patch === null) {
            return null;
        }

        return [
            'patchId' => $patchId,
            'status' => $patch->status(),
            'score' => $patch->score(),
            'grade' => $patch->toArray()['grade'],
            'mergeReadiness' => $patch->mergeReadiness()->toArray(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function reviewQueue(): array
    {
        $queue = [];
        foreach ($this->pipeline->list(null, null, null) as $patch) {
            if (!in_array($patch->status(), [
                'under_review',
                'changes_requested',
                'approved',
            ], true)) {
                continue;
            }
            $data = $patch->toArray();
            $queue[] = [
                'patchId' => $data['patchId'],
                'missionId' => $data['missionId'],
                'status' => $data['status'],
                'score' => $data['score'],
                'grade' => $data['grade'],
                'mergeReady' => ($data['mergeReadiness']['ready'] ?? false) === true,
                'updatedAtUtc' => $data['updatedAtUtc'],
                'findingCount' => array_sum(array_map(
                    static fn ($r) => count($r['findings'] ?? []),
                    $data['reviews'] ?? []
                )),
            ];
        }

        return $queue;
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->settings->get();
    }

    /** @param array<string, mixed> $patch */
    public function updateSettings(array $patch): array
    {
        return $this->settings->update($patch);
    }

    /** @return list<array<string, mixed>> */
    public function listReviewProviders(): array
    {
        $items = [];
        foreach ($this->reviewProviders->all() as $provider) {
            $items[] = [
                'id' => $provider->id(),
                'displayName' => $provider->displayName(),
            ];
        }

        return $items;
    }
}
