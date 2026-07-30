<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Execution\Ssh;

/**
 * Raw outcome from a RemoteCommandRunner invocation.
 */
final class SSHResult
{
    public function __construct(
        private int $exitCode,
        private string $stdout,
        private string $stderr,
        private bool $timedOut = false,
        private bool $connectionError = false,
        private string $message = '',
    ) {
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function stdout(): string
    {
        return $this->stdout;
    }

    public function stderr(): string
    {
        return $this->stderr;
    }

    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    public function connectionError(): bool
    {
        return $this->connectionError;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function isSuccess(): bool
    {
        return !$this->timedOut && !$this->connectionError && $this->exitCode === 0;
    }
}
