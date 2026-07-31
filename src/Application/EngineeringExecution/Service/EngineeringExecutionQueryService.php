<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Service;

use Aep\Application\EngineeringExecution\Port\ExecutionSessionStore;
use Aep\Application\EngineeringExecution\Port\ExecutionSettingsStore;
use Aep\Application\EngineeringExecution\Port\ProviderRegistry;

/**
 * Read-side façade for Mission Control execution panels.
 */
final class EngineeringExecutionQueryService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ExecutionSessionStore $sessions,
        private readonly ExecutionSettingsStore $settings,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listProviders(): array
    {
        $items = [];
        foreach ($this->registry->all() as $provider) {
            $items[] = [
                'id' => $provider->id(),
                'displayName' => $provider->displayName(),
                'capabilities' => $provider->capabilities()->toArray(),
                'health' => $provider->health()->toArray(),
            ];
        }

        return $items;
    }

    /** @return array<string, mixed>|null */
    public function getProvider(string $providerId): ?array
    {
        if (!$this->registry->has($providerId)) {
            return null;
        }
        $provider = $this->registry->get($providerId);

        return [
            'id' => $provider->id(),
            'displayName' => $provider->displayName(),
            'capabilities' => $provider->capabilities()->toArray(),
            'health' => $provider->health()->toArray(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function sessionForMission(string $missionId): ?array
    {
        $session = $this->sessions->findLatestForMission($missionId);

        return $session?->toArray();
    }

    /** @return array<string, mixed>|null */
    public function session(string $sessionId): ?array
    {
        return $this->sessions->find($sessionId)?->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function events(string $sessionId, int $afterSeq = 0): array
    {
        $out = [];
        foreach ($this->sessions->events($sessionId, $afterSeq) as $event) {
            $out[] = $event->toArray();
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function promptPreview(string $sessionId): ?array
    {
        $session = $this->sessions->find($sessionId);
        if ($session === null) {
            return null;
        }
        $prompt = $session->prompt();

        return [
            'sessionId' => $sessionId,
            'providerId' => $session->providerId(),
            'prompt' => $prompt?->toArray(),
            'preview' => $prompt !== null
                ? "# System\n\n" . $prompt->system() . "\n\n# User\n\n" . $prompt->user()
                : null,
        ];
    }

    /** @return array<string, mixed>|null */
    public function metrics(string $sessionId): ?array
    {
        $session = $this->sessions->find($sessionId);
        if ($session === null) {
            return null;
        }

        return [
            'sessionId' => $sessionId,
            'providerId' => $session->providerId(),
            'status' => $session->status(),
            'usage' => $session->usage()->toArray(),
            'timeline' => $session->timeline(),
        ];
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
}
