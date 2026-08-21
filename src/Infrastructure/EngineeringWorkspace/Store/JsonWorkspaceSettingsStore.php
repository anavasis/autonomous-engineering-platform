<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringWorkspace\Store;

use Aep\Application\EngineeringWorkspace\Port\WorkspaceSettingsStore;

final class JsonWorkspaceSettingsStore implements WorkspaceSettingsStore
{
    private readonly string $path;

    public function __construct(string $workspacesRoot)
    {
        $root = rtrim($workspacesRoot, "/\\");
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('Unable to create workspaces settings root.');
        }
        $this->path = $root . '/settings.json';
        if (!is_file($this->path)) {
            $this->write([
                'maxBytes' => 2147483648,
                'maxFiles' => 100000,
                'maxDurationSeconds' => 86400,
                'maxConcurrentWorkspaces' => 32,
                'retainFailedDays' => 14,
                'retainSealedDays' => 30,
                'keepLatestN' => 20,
            ]);
        }
    }

    public function get(): array
    {
        $data = json_decode((string) file_get_contents($this->path), true);

        return is_array($data) ? $data : [];
    }

    public function update(array $patch): array
    {
        $current = $this->get();
        foreach ([
            'maxBytes',
            'maxFiles',
            'maxDurationSeconds',
            'maxConcurrentWorkspaces',
            'retainFailedDays',
            'retainSealedDays',
            'keepLatestN',
        ] as $key) {
            if (array_key_exists($key, $patch) && is_int($patch[$key])) {
                $current[$key] = max(0, $patch[$key]);
            }
        }
        $this->write($current);

        return $current;
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        file_put_contents($this->path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
}
