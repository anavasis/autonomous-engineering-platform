<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Git;

/**
 * Resolves SecretRef pointer values to plaintext credentials.
 *
 * Credentials must never leave Infrastructure (no metadata, no logs, no return to Application).
 */
interface SecretResolver
{
    /**
     * @return string|null Plaintext secret, or null when unavailable / not configured
     */
    public function resolve(?string $vault, ?string $key): ?string;
}
