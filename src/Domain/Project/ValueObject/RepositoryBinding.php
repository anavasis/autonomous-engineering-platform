<?php

declare(strict_types=1);

namespace Aep\Domain\Project\ValueObject;

/**
 * Exactly one repository binding per Project (R7a).
 */
final class RepositoryBinding
{
    public function __construct(
        private string $provider,
        private string $repository,
        private ?SecretRef $secretRef = null
    ) {
        $provider = trim($provider);
        $repository = trim($repository);
        if ($provider === '' || $repository === '') {
            throw new \InvalidArgumentException('RepositoryBinding provider and repository must be non-empty.');
        }
        if ($this->containsCredentialHint($provider) || $this->containsCredentialHint($repository)) {
            throw new \InvalidArgumentException('RepositoryBinding must not embed credentials.');
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

    public function secretRef(): ?SecretRef
    {
        return $this->secretRef;
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
