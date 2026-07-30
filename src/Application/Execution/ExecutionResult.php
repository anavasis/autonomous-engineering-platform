<?php

declare(strict_types=1);

namespace Aep\Application\Execution;

/**
 * Immutable execution outcome.
 */
final class ExecutionResult
{
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';

    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        private string $executorId,
        private string $status,
        private string $message,
        private array $context = []
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function succeeded(string $executorId, string $message = '', array $context = []): self
    {
        return self::create($executorId, self::SUCCEEDED, trim($message), $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function failed(string $executorId, string $message, array $context = []): self
    {
        $message = trim($message);
        if ($message === '') {
            throw new \InvalidArgumentException('failed message must be non-empty.');
        }

        return self::create($executorId, self::FAILED, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function rejected(string $executorId, string $message, array $context = []): self
    {
        $message = trim($message);
        if ($message === '') {
            throw new \InvalidArgumentException('rejected message must be non-empty.');
        }

        return self::create($executorId, self::REJECTED, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function create(string $executorId, string $status, string $message, array $context): self
    {
        $executorId = trim($executorId);
        if ($executorId === '') {
            throw new \InvalidArgumentException('executorId must be non-empty.');
        }

        return new self($executorId, $status, $message, $context);
    }

    public function executorId(): string
    {
        return $this->executorId;
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
