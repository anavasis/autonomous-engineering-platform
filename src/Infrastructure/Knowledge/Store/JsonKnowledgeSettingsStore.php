<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Knowledge\Store;

use Aep\Application\Knowledge\Port\KnowledgeSettingsStore;

final class JsonKnowledgeSettingsStore implements KnowledgeSettingsStore
{
    private readonly string $path;

    public function __construct(string $knowledgeRoot)
    {
        $root = rtrim($knowledgeRoot, "/\\");
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('Unable to create knowledge settings dir.');
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
        // keep only known keys + nested rankWeights
        $out = $this->defaults();
        foreach ($out as $key => $default) {
            if (array_key_exists($key, $merged)) {
                $out[$key] = $merged[$key];
            }
        }
        if (isset($merged['rankWeights']) && is_array($merged['rankWeights'])) {
            $out['rankWeights'] = array_merge($out['rankWeights'], $merged['rankWeights']);
        }
        file_put_contents($this->path, json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $out;
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'autoRetrieveEnabled' => true,
            'autoCaptureOnExecution' => true,
            'embeddingProviderId' => 'local_lexical',
            'maxNotes' => 12,
            'archiveMaxAgeDays' => 180,
            'archiveMinUsefulness' => 0.15,
            'forgetArchiveTtlDays' => 90,
            'rankWeights' => [
                'lexical' => 1.0,
                'embedding' => 0.8,
                'recency' => 0.3,
                'usefulness' => 0.4,
            ],
        ];
    }
}
