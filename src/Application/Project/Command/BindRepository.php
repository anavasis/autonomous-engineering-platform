<?php

declare(strict_types=1);

namespace Aep\Application\Project\Command;

final class BindRepository
{
    public function __construct(
        private string $projectId,
        private string $provider,
        private string $repository,
        private string $occurredAtUtc,
        private ?string $secretVault = null,
        private ?string $secretKey = null,
        private ?string $secretVersion = null
    ) {
        $this->projectId = self::req($projectId, 'projectId');
        $this->provider = self::req($provider, 'provider');
        $this->repository = self::req($repository, 'repository');
        $this->occurredAtUtc = self::req($occurredAtUtc, 'occurredAtUtc');
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function repository(): string
    {
        return $this->repository;
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAtUtc;
    }

    public function secretVault(): ?string
    {
        return $this->secretVault;
    }

    public function secretKey(): ?string
    {
        return $this->secretKey;
    }

    public function secretVersion(): ?string
    {
        return $this->secretVersion;
    }

    private static function req(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' must be non-empty.');
        }

        return $value;
    }
}
