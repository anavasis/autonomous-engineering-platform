<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Service;

use Aep\Application\Planning\Model\Program;

final class ResourceAllocator
{
    /**
     * @return array{inFlight: int, maxInFlight: int, reservations: list<array<string, mixed>>}
     */
    public function snapshot(Program $program, int $maxInFlight): array
    {
        $inFlight = 0;
        $reservations = [];
        foreach ($program->graph()->nodes() as $node) {
            if (in_array($node->status(), ['launching', 'running', 'queued'], true)) {
                $inFlight++;
                $reservations[] = [
                    'nodeId' => $node->nodeId(),
                    'status' => $node->status(),
                    'kind' => 'compute',
                ];
            }
        }

        return [
            'inFlight' => $inFlight,
            'maxInFlight' => $maxInFlight,
            'reservations' => $reservations,
        ];
    }
}
