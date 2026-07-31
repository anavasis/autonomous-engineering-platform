<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Planning\Store;

use Aep\Application\Planning\Port\PlanningSettingsStore;

final class JsonPlanningSettingsStore implements PlanningSettingsStore
{
    private readonly string $path;

    public function __construct(string $planningRoot)
    {
        $root = rtrim($planningRoot, "/\\");
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('Unable to create planning settings dir.');
        }
        $this->path = $root . '/settings.json';
        if (!is_file($this->path)) {
            $this->put($this->defaults());
        }
    }

    public function get(): array
    {
        if (!is_file($this->path)) {
            return $this->defaults();
        }
        $raw = @file_get_contents($this->path);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? array_merge($this->defaults(), $data) : $this->defaults();
    }

    public function put(array $settings): array
    {
        $current = is_file($this->path) ? $this->get() : $this->defaults();
        $merged = array_merge($current, $settings);
        $out = $this->defaults();
        foreach ($out as $key => $_) {
            if (array_key_exists($key, $merged)) {
                $out[$key] = $merged[$key];
            }
        }
        file_put_contents($this->path, json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $out;
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'maxInFlight' => 3,
            'maxConcurrentWorkspaces' => 32,
            'snapshotEveryEvents' => 5,
            'defaultFailurePolicy' => 'retry',
            'autoPlanOnCreate' => true,
            'knowledgeAssistedPlanning' => true,
        ];
    }
}
