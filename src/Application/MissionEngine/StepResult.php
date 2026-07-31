<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

/**
 * Result of a single MissionStep execution.
 */
final class StepResult
{
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';
    public const WAITING = 'waiting';
    public const CANCELLED = 'cancelled';
    public const TIMED_OUT = 'timed_out';

    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        private string $status,
        private string $message,
        private bool $retryable,
        private array $context = []
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function succeeded(string $message = '', array $context = []): self
    {
        return new self(self::SUCCEEDED, trim($message), false, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function failed(string $message, bool $retryable = false, array $context = []): self
    {
        $message = trim($message);
        if ($message === '') {
            throw new \InvalidArgumentException('failed message must be non-empty.');
        }

        return new self(self::FAILED, $message, $retryable, $context);
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

        return new self(self::REJECTED, $message, false, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function waiting(string $message = 'Waiting for manual gate.', array $context = []): self
    {
        return new self(self::WAITING, trim($message), false, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function cancelled(string $message = 'Cancelled.', array $context = []): self
    {
        return new self(self::CANCELLED, trim($message), false, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function timedOut(string $message = 'Step timed out.', array $context = []): self
    {
        return new self(self::TIMED_OUT, trim($message), false, $context);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
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

    public function isWaiting(): bool
    {
        return $this->status === self::WAITING;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function isTimedOut(): bool
    {
        return $this->status === self::TIMED_OUT;
    }
}
