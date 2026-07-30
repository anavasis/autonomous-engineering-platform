<?php

declare(strict_types=1);

namespace Aep\Application\Git;

/**
 * Immutable Git operation result.
 */
final class GitResult
{
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';

    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        private string $status,
        private string $message,
        private array $context = []
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function succeeded(string $message = '', array $context = []): self
    {
        return new self(self::SUCCEEDED, trim($message), $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function failed(string $message, array $context = []): self
    {
        $message = trim($message);
        if ($message === '') {
            throw new \InvalidArgumentException('failed message must be non-empty.');
        }

        return new self(self::FAILED, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function rejected(string $message, array $context = []): self
    {
        $message = trim($message);
        if ($message === '') {
            throw new \InvalidArgumentException('rejected message must be non-empty.');
        }

        return new self(self::REJECTED, $message, $context);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }
}
