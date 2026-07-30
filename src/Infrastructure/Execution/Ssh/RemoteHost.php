<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Execution\Ssh;

/**
 * Infrastructure remote host target for SSH execution.
 */
final class RemoteHost
{
    public function __construct(
        private string $host,
        private string $user,
        private int $port = 22,
        private int $connectTimeoutSeconds = 10,
        private ?string $serverId = null,
        private ?string $remoteWorkspace = null,
    ) {
        $this->host = trim($host);
        $this->user = trim($user);
        if ($this->host === '') {
            throw new \InvalidArgumentException('RemoteHost host must be non-empty.');
        }
        if ($this->user === '') {
            throw new \InvalidArgumentException('RemoteHost user must be non-empty.');
        }
        if (str_contains($this->host, '://') && str_contains($this->host, '@')) {
            throw new \InvalidArgumentException('RemoteHost must not embed credentials.');
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new \InvalidArgumentException('RemoteHost port must be 1..65535.');
        }
        if ($this->connectTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('connectTimeoutSeconds must be >= 1.');
        }
    }

    public function host(): string
    {
        return $this->host;
    }

    public function user(): string
    {
        return $this->user;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function connectTimeoutSeconds(): int
    {
        return $this->connectTimeoutSeconds;
    }

    public function serverId(): ?string
    {
        return $this->serverId;
    }

    public function remoteWorkspace(): ?string
    {
        return $this->remoteWorkspace;
    }

    public function target(): string
    {
        return $this->user . '@' . $this->host;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function fromContext(array $context): self
    {
        $host = $context['host'] ?? null;
        $user = $context['user'] ?? null;
        if (!is_string($host) || !is_string($user)) {
            throw new \InvalidArgumentException('SSH context requires string host and user.');
        }

        $port = 22;
        if (isset($context['port']) && (is_int($context['port']) || is_numeric($context['port']))) {
            $port = (int) $context['port'];
        }

        $connectTimeout = 10;
        if (isset($context['connectTimeoutSeconds']) && (is_int($context['connectTimeoutSeconds']) || is_numeric($context['connectTimeoutSeconds']))) {
            $connectTimeout = (int) $context['connectTimeoutSeconds'];
        }

        $serverId = isset($context['serverId']) && is_string($context['serverId']) ? $context['serverId'] : null;
        $workspace = isset($context['remoteWorkspace']) && is_string($context['remoteWorkspace'])
            ? $context['remoteWorkspace']
            : null;

        return new self($host, $user, $port, $connectTimeout, $serverId, $workspace);
    }
}
