<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Git;

/**
 * In-memory SecretResolver for tests and local wiring.
 *
 * Keys are "{vault}/{key}". Values are never written to disk by this class.
 */
final class MapSecretResolver implements SecretResolver
{
    /** @param array<string, string> $secrets */
    public function __construct(
        private readonly array $secrets = [],
    ) {
    }

    public function resolve(?string $vault, ?string $key): ?string
    {
        if ($vault === null || $vault === '' || $key === null || $key === '') {
            return null;
        }

        $mapKey = $vault . '/' . $key;

        return $this->secrets[$mapKey] ?? null;
    }
}
