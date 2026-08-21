<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Model;

final class ProviderResult
{
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';
    public const TIMED_OUT = 'timed_out';

    /**
     * @param list<string> $filesChanged
     * @param array<string, mixed> $rawMeta
     */
    public function __construct(
        private string $status,
        private string $message,
        private array $filesChanged = [],
        private ?string $diffText = null,
        private UsageMetrics $usage = new UsageMetrics(),
        private array $rawMeta = [],
    ) {
        if (!in_array($status, [
            self::SUCCEEDED,
            self::FAILED,
            self::REJECTED,
            self::CANCELLED,
            self::TIMED_OUT,
        ], true)) {
            throw new \InvalidArgumentException('Invalid ProviderResult status.');
        }
    }

    public function status(): string
    {
        return $this->status;
    }

    public function message(): string
    {
        return $this->message;
    }

    /** @return list<string> */
    public function filesChanged(): array
    {
        return $this->filesChanged;
    }

    public function diffText(): ?string
    {
        return $this->diffText;
    }

    public function usage(): UsageMetrics
    {
        return $this->usage;
    }

    /** @return array<string, mixed> */
    public function rawMeta(): array
    {
        return $this->rawMeta;
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'filesChanged' => $this->filesChanged,
            'diffText' => $this->diffText,
            'usage' => $this->usage->toArray(),
            'rawMeta' => $this->rawMeta,
        ];
    }
}
