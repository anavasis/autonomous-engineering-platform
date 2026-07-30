<?php

declare(strict_types=1);

namespace Aep\Application\Project\Command;

final class AddServerDefinition
{
    public function __construct(
        private string $projectId,
        private string $serverId,
        private string $label,
        private string $host,
        private string $accessMethod,
        private string $occurredAtUtc,
        private ?string $secretVault = null,
        private ?string $secretKey = null,
        private ?string $secretVersion = null
    ) {
        $this->projectId = self::req($projectId, 'projectId');
        $this->serverId = self::req($serverId, 'serverId');
        $this->label = self::req($label, 'label');
        $this->host = self::req($host, 'host');
        $this->accessMethod = self::req($accessMethod, 'accessMethod');
        $this->occurredAtUtc = self::req($occurredAtUtc, 'occurredAtUtc');
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    public function serverId(): string
    {
        return $this->serverId;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function accessMethod(): string
    {
        return $this->accessMethod;
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
