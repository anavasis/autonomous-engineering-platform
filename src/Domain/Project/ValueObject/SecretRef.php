<?php

declare(strict_types=1);

namespace Aep\Domain\Project\ValueObject;

/**
 * Pointer to secret material only — never the secret itself.
 */
final class SecretRef
{
    public function __construct(
        private string $vault,
        private string $key,
        private ?string $version = null
    ) {
        $vault = trim($vault);
        $key = trim($key);
        if ($vault === '' || $key === '') {
            throw new \InvalidArgumentException('SecretRef vault and key must be non-empty.');
        }
        if ($this->looksLikeSecret($vault) || $this->looksLikeSecret($key)) {
            throw new \InvalidArgumentException('SecretRef must not embed secret material.');
        }
        if ($version !== null) {
            $version = trim($version);
            if ($version === '') {
                throw new \InvalidArgumentException('SecretRef version must be non-empty when provided.');
            }
        }
        $this->vault = $vault;
        $this->key = $key;
        $this->version = $version;
    }

    public function vault(): string
    {
        return $this->vault;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    private function looksLikeSecret(string $value): bool
    {
        if (str_contains($value, '://') && str_contains($value, '@')) {
            return true;
        }
        if (preg_match('/^(sk-|ghp_|xox[baprs]-)/i', $value) === 1) {
            return true;
        }

        return strlen($value) > 128;
    }
}
