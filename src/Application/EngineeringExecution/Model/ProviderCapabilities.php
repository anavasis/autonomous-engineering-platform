<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Model;

final class ProviderCapabilities
{
    public function __construct(
        private bool $streaming = false,
        private bool $cancel = true,
        private bool $resume = false,
        private bool $workspaceMount = true,
        private bool $diffExport = true,
        private bool $tools = false,
        private int $maxContextTokens = 128000,
        private bool $supportsImages = false,
        private bool $costReporting = false,
        private bool $parallelSessions = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'streaming' => $this->streaming,
            'cancel' => $this->cancel,
            'resume' => $this->resume,
            'workspaceMount' => $this->workspaceMount,
            'diffExport' => $this->diffExport,
            'tools' => $this->tools,
            'maxContextTokens' => $this->maxContextTokens,
            'supportsImages' => $this->supportsImages,
            'costReporting' => $this->costReporting,
            'parallelSessions' => $this->parallelSessions,
        ];
    }

    public function streaming(): bool
    {
        return $this->streaming;
    }

    public function cancel(): bool
    {
        return $this->cancel;
    }

    public function resume(): bool
    {
        return $this->resume;
    }

    public function diffExport(): bool
    {
        return $this->diffExport;
    }

    public function costReporting(): bool
    {
        return $this->costReporting;
    }

    public function maxContextTokens(): int
    {
        return $this->maxContextTokens;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            ($data['streaming'] ?? false) === true,
            ($data['cancel'] ?? true) === true,
            ($data['resume'] ?? false) === true,
            ($data['workspaceMount'] ?? true) === true,
            ($data['diffExport'] ?? true) === true,
            ($data['tools'] ?? false) === true,
            is_int($data['maxContextTokens'] ?? null) ? $data['maxContextTokens'] : 128000,
            ($data['supportsImages'] ?? false) === true,
            ($data['costReporting'] ?? false) === true,
            ($data['parallelSessions'] ?? false) === true,
        );
    }
}
