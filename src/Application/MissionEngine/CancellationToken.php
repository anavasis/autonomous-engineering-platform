<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

/**
 * Cooperative cancellation flag for a mission run.
 */
final class CancellationToken
{
    private bool $cancelled = false;
    private string $reason = '';

    public function cancel(string $reason = 'Cancelled by caller.'): void
    {
        $this->cancelled = true;
        $this->reason = trim($reason) !== '' ? trim($reason) : 'Cancelled by caller.';
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
