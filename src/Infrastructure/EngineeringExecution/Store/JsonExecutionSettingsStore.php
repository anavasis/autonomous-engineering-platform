<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringExecution\Store;

use Aep\Application\EngineeringExecution\Port\ExecutionSettingsStore;

final class JsonExecutionSettingsStore implements ExecutionSettingsStore
{
    private readonly string $path;

    public function __construct(string $executionRoot)
    {
        $root = rtrim($executionRoot, "/\\");
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('Unable to create execution settings dir.');
        }
        $this->path = $root . '/settings.json';
        if (!is_file($this->path)) {
            $this->write([
                'defaultProviderId' => null,
                'fallbackProviders' => ['local-agent'],
                'enabledProviderIds' => null,
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $data = json_decode((string) file_get_contents($this->path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function update(array $patch): array
    {
        $current = $this->get();
        if (array_key_exists('defaultProviderId', $patch)) {
            $value = $patch['defaultProviderId'];
            $current['defaultProviderId'] = is_string($value) && $value !== '' ? $value : null;
        }
        if (isset($patch['fallbackProviders']) && is_array($patch['fallbackProviders'])) {
            $list = [];
            foreach ($patch['fallbackProviders'] as $id) {
                if (is_string($id) && $id !== '') {
                    $list[] = $id;
                }
            }
            $current['fallbackProviders'] = $list;
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
