<?php

declare(strict_types=1);

namespace Aep\Application\Git;

/**
 * Ordered Git operation log (newest last).
 */
final class GitOperationLog
{
    /**
     * @param list<GitOperationLogEntry> $entries
     */
    public function __construct(
        private array $entries
    ) {
        foreach ($this->entries as $entry) {
            if (!$entry instanceof GitOperationLogEntry) {
                throw new \InvalidArgumentException('GitOperationLog entries must be GitOperationLogEntry.');
            }
        }
    }

    /**
     * @return list<GitOperationLogEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
