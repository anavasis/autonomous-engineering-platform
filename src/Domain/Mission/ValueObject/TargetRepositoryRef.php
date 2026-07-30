<?php

declare(strict_types=1);

namespace Aep\Domain\Mission\ValueObject;

/**
 * External target repository reference only (never credentials or cloned source).
 */
final class TargetRepositoryRef
{
    public function __construct(
        private string $provider,
        private string $repository
    ) {
        $provider = trim($provider);
        $repository = trim($repository);
        if ($provider === '' || $repository === '') {
            throw new \InvalidArgumentException('TargetRepositoryRef provider and repository must be non-empty.');
        }
        if ($this->containsCredentialHint($provider) || $this->containsCredentialHint($repository)) {
            throw new \InvalidArgumentException('TargetRepositoryRef must not embed credentials.');
        }
        $this->provider = $provider;
        $this->repository = $repository;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function repository(): string
    {
        return $this->repository;
    }

    public function toString(): string
    {
        return $this->provider . ':' . $this->repository;
    }

    private function containsCredentialHint(string $value): bool
    {
        return str_contains($value, '://') && str_contains($value, '@');
    }
}
