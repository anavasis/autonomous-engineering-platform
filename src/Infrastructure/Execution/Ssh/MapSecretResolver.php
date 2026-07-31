<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Execution\Ssh;

/**
 * In-memory SecretResolver for tests and local wiring.
 *
 * Keys are "{vault}/{key}".
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

        return $this->secrets[$vault . '/' . $key] ?? null;
    }
}
