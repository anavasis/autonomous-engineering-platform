<?php

declare(strict_types=1);

namespace Aep\Domain\Mission\ValueObject;

/**
 * Inspection package submitted for approval.
 */
final class InspectionFindings
{
    public function __construct(
        private string $summary,
        private string $status = 'ready'
    ) {
        $summary = trim($summary);
        $status = trim($status);
        if ($summary === '') {
            throw new \InvalidArgumentException('InspectionFindings summary must be non-empty.');
        }
        if ($status === '') {
            throw new \InvalidArgumentException('InspectionFindings status must be non-empty.');
        }
        $this->summary = $summary;
        $this->status = $status;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function status(): string
    {
        return $this->status;
    }
}
