<?php

declare(strict_types=1);

namespace Aep\Application\Acceptance\Port;

use Aep\Application\Acceptance\Model\AcceptanceReport;

interface AcceptanceReportStore
{
    public function save(AcceptanceReport $report): void;

    public function get(string $reportId): ?AcceptanceReport;

    /** @return list<AcceptanceReport> */
    public function list(?string $projectName = null): array;
}
