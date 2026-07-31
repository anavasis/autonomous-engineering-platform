<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Knowledge\Provider;

use Aep\Application\Knowledge\Port\EmbeddingProvider;
use Aep\Application\Knowledge\Port\EmbeddingProviderRegistry;
use Aep\Infrastructure\Knowledge\Embedding\LocalLexicalEmbeddingProvider;
use Aep\Infrastructure\Knowledge\Embedding\StubEmbeddingProvider;

final class ConfigEmbeddingProviderRegistry implements EmbeddingProviderRegistry
{
    /** @var array<string, EmbeddingProvider> */
    private array $providers = [];

    private string $defaultId;

    /**
     * @param array<string, mixed> $config
     * @param array<string, callable(array<string, mixed>): EmbeddingProvider> $factories
     */
    public function __construct(array $config, array $factories = [])
    {
        $factories = $factories + [
            'local_lexical' => static fn (array $o): EmbeddingProvider => new LocalLexicalEmbeddingProvider(
                is_int($o['dimensions'] ?? null) ? $o['dimensions'] : 64
            ),
            'stub_embed' => static fn (array $o): EmbeddingProvider => new StubEmbeddingProvider(),
        ];
        $this->defaultId = is_string($config['default'] ?? null) ? $config['default'] : 'local_lexical';
        $list = is_array($config['providers'] ?? null) ? $config['providers'] : [
            ['id' => 'local_lexical', 'type' => 'local_lexical', 'enabled' => true, 'displayName' => 'Local Lexical Embedding'],
        ];
        foreach ($list as $entry) {
            if (!is_array($entry) || ($entry['enabled'] ?? true) !== true) {
                continue;
            }
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';
            $type = is_string($entry['type'] ?? null) ? $entry['type'] : $id;
            if ($id === '' || !isset($factories[$type])) {
                continue;
            }
            $options = is_array($entry['options'] ?? null) ? $entry['options'] : [];
            $this->providers[$id] = $factories[$type]($options);
        }
        if ($this->providers === []) {
            $this->providers['local_lexical'] = new LocalLexicalEmbeddingProvider();
            $this->defaultId = 'local_lexical';
        }
        if (!isset($this->providers[$this->defaultId])) {
            $this->defaultId = array_key_first($this->providers) ?? 'local_lexical';
        }
    }

    public function get(string $id): ?EmbeddingProvider
    {
        return $this->providers[$id] ?? null;
    }

    public function defaultProvider(): EmbeddingProvider
    {
        return $this->providers[$this->defaultId] ?? new LocalLexicalEmbeddingProvider();
    }

    public function list(): array
    {
        $out = [];
        foreach ($this->providers as $id => $provider) {
            $out[] = [
                'id' => $id,
                'displayName' => $provider->displayName(),
                'health' => $provider->health(),
                'default' => $id === $this->defaultId,
            ];
        }

        return $out;
    }
}
