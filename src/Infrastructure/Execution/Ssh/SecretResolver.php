<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Execution\Ssh;

/**
 * Resolves SecretRef pointers to plaintext credentials for SSH auth.
 *
 * Credentials must never leave Infrastructure (no audit plaintext, no ExecutionResult).
 */
interface SecretResolver
{
    /**
     * @return string|null Plaintext secret, or null when unavailable / not configured
     */
    public function resolve(?string $vault, ?string $key): ?string;
}
