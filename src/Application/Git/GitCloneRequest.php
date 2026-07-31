<?php

declare(strict_types=1);

namespace Aep\Application\Git;

final class GitCloneRequest extends GitProjectRequest
{
    public function __construct(
        string $projectId,
        string $occurredAtUtc,
        private string $provider,
        private string $repository,
        private ?string $secretVault = null,
        private ?string $secretKey = null
    ) {
        parent::__construct($projectId, $occurredAtUtc);
        $this->provider = self::req($provider, 'provider');
        $this->repository = self::req($repository, 'repository');
        if ($this->containsCredentialHint($this->provider) || $this->containsCredentialHint($this->repository)) {
            throw new \InvalidArgumentException('Clone request must not embed credentials.');
        }
        if (($secretVault === null) xor ($secretKey === null)) {
            throw new \InvalidArgumentException('secretVault and secretKey must both be provided or both omitted.');
        }
        $this->secretVault = $secretVault !== null ? trim($secretVault) : null;
        $this->secretKey = $secretKey !== null ? trim($secretKey) : null;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function repository(): string
    {
        return $this->repository;
    }

    public function secretVault(): ?string
    {
        return $this->secretVault;
    }

    public function secretKey(): ?string
    {
        return $this->secretKey;
    }

    private function containsCredentialHint(string $value): bool
    {
        return str_contains($value, '://') && str_contains($value, '@');
    }
}
