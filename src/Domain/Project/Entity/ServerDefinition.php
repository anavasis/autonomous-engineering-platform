<?php

declare(strict_types=1);

namespace Aep\Domain\Project\Entity;

use Aep\Domain\Project\ValueObject\AccessMethod;
use Aep\Domain\Project\ValueObject\HostRef;
use Aep\Domain\Project\ValueObject\SecretRef;
use Aep\Domain\Project\ValueObject\ServerDefinitionId;

final class ServerDefinition
{
    public function __construct(
        private ServerDefinitionId $id,
        private string $label,
        private HostRef $host,
        private AccessMethod $accessMethod,
        private ?SecretRef $secretRef = null
    ) {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('ServerDefinition label must be non-empty.');
        }
        $this->label = $label;
    }

    public function id(): ServerDefinitionId
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function host(): HostRef
    {
        return $this->host;
    }

    public function accessMethod(): AccessMethod
    {
        return $this->accessMethod;
    }

    public function secretRef(): ?SecretRef
    {
        return $this->secretRef;
    }
}
