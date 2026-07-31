<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringExecution\Registry;

use Aep\Application\EngineeringExecution\Port\EngineeringExecutionProvider;
use Aep\Application\EngineeringExecution\Port\ProviderRegistry;

/**
 * Configuration-driven provider discovery.
 *
 * New providers are added by shipping a provider class and a config entry —
 * no changes to Mission Engine, Orchestrator, or other providers.
 */
final class ConfigProviderRegistry implements ProviderRegistry
{
    /** @var array<string, EngineeringExecutionProvider> */
    private array $providers = [];

    /**
     * @param array<string, mixed> $config
     * @param array<string, callable(array<string, mixed>): EngineeringExecutionProvider> $factories
     *        keyed by provider type (or fully-qualified class name)
     */
    public function __construct(array $config, array $factories)
    {
        $entries = $config['providers'] ?? [];
        if (!is_array($entries)) {
            throw new \InvalidArgumentException('providers config must be a list.');
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['enabled'] ?? true) !== true) {
                continue;
            }
            $id = isset($entry['id']) && is_string($entry['id']) ? trim($entry['id']) : '';
            $type = isset($entry['type']) && is_string($entry['type']) ? trim($entry['type']) : '';
            if ($id === '' || $type === '') {
                throw new \InvalidArgumentException('Each provider requires id and type.');
            }
            if (!isset($factories[$type])) {
                throw new \InvalidArgumentException('No factory registered for provider type: ' . $type);
            }
            $options = isset($entry['options']) && is_array($entry['options']) ? $entry['options'] : [];
            $options['id'] = $id;
            if (isset($entry['displayName']) && is_string($entry['displayName'])) {
                $options['displayName'] = $entry['displayName'];
            }
            $provider = $factories[$type]($options);
            if (!$provider instanceof EngineeringExecutionProvider) {
                throw new \RuntimeException('Factory for ' . $type . ' did not return a provider.');
            }
            if ($provider->id() !== $id) {
                throw new \RuntimeException('Provider id mismatch for type ' . $type);
            }
            $this->providers[$id] = $provider;
        }
    }

    public function has(string $providerId): bool
    {
        return isset($this->providers[$providerId]);
    }

    public function get(string $providerId): EngineeringExecutionProvider
    {
        if (!isset($this->providers[$providerId])) {
            throw new \InvalidArgumentException('Unknown execution provider: ' . $providerId);
        }

        return $this->providers[$providerId];
    }

    public function all(): array
    {
        return array_values($this->providers);
    }

    public function ids(): array
    {
        return array_keys($this->providers);
    }
}
