<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Execution\Ssh;

/**
 * Ephemeral SSH authentication material (Infrastructure only).
 */
final class SSHAuthentication
{
    public const PASSWORD = 'password';
    public const PRIVATE_KEY = 'private_key';

    private function __construct(
        private string $method,
        private ?string $password = null,
        private ?string $privateKey = null,
        private ?string $passphrase = null,
    ) {
    }

    public static function withPassword(string $password): self
    {
        $password = trim($password);
        if ($password === '') {
            throw new \InvalidArgumentException('SSH password must be non-empty.');
        }

        return new self(self::PASSWORD, $password);
    }

    public static function withPrivateKey(string $privateKey, ?string $passphrase = null): self
    {
        $privateKey = trim($privateKey);
        if ($privateKey === '') {
            throw new \InvalidArgumentException('SSH private key must be non-empty.');
        }
        if ($passphrase !== null) {
            $passphrase = trim($passphrase);
            if ($passphrase === '') {
                $passphrase = null;
            }
        }

        return new self(self::PRIVATE_KEY, null, $privateKey, $passphrase);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function password(): ?string
    {
        return $this->password;
    }

    public function privateKey(): ?string
    {
        return $this->privateKey;
    }

    public function passphrase(): ?string
    {
        return $this->passphrase;
    }

    public function isPassword(): bool
    {
        return $this->method === self::PASSWORD;
    }

    public function isPrivateKey(): bool
    {
        return $this->method === self::PRIVATE_KEY;
    }
}
