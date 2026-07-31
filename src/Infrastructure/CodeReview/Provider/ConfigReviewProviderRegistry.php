<?php

declare(strict_types=1);

namespace Aep\Infrastructure\CodeReview\Provider;

use Aep\Application\CodeReview\Port\ReviewProvider;
use Aep\Application\CodeReview\Port\ReviewProviderRegistry;

final class ConfigReviewProviderRegistry implements ReviewProviderRegistry
{
    /** @var array<string, ReviewProvider> */
    private array $providers = [];

    /**
     * @param array<string, mixed> $config
     * @param array<string, callable(array<string, mixed>): ReviewProvider> $factories
     */
    public function __construct(array $config, array $factories)
    {
        $entries = $config['providers'] ?? [];
        if (!is_array($entries)) {
            throw new \InvalidArgumentException('review providers config must be a list.');
        }
        foreach ($entries as $entry) {
            if (!is_array($entry) || ($entry['enabled'] ?? true) !== true) {
                continue;
            }
            $id = is_string($entry['id'] ?? null) ? trim((string) $entry['id']) : '';
            $type = is_string($entry['type'] ?? null) ? trim((string) $entry['type']) : '';
            if ($id === '' || $type === '' || !isset($factories[$type])) {
                throw new \InvalidArgumentException('Invalid review provider entry.');
            }
            $options = isset($entry['options']) && is_array($entry['options']) ? $entry['options'] : [];
            $options['id'] = $id;
            if (isset($entry['displayName']) && is_string($entry['displayName'])) {
                $options['displayName'] = $entry['displayName'];
            }
            $provider = $factories[$type]($options);
            $this->providers[$provider->id()] = $provider;
        }
    }

    public function has(string $providerId): bool
    {
        return isset($this->providers[$providerId]);
    }

    public function get(string $providerId): ReviewProvider
    {
        if (!isset($this->providers[$providerId])) {
            throw new \InvalidArgumentException('Unknown review provider: ' . $providerId);
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
