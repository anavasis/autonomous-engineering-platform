<?php

declare(strict_types=1);

namespace Aep\Application\Git;

/**
 * Single Git operation log entry.
 */
final class GitOperationLogEntry
{
    public function __construct(
        private string $timestamp,
        private string $operation,
        private string $result,
        private string $message
    ) {
    }

    public function timestamp(): string
    {
        return $this->timestamp;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function result(): string
    {
        return $this->result;
    }

    public function message(): string
    {
        return $this->message;
    }
}
