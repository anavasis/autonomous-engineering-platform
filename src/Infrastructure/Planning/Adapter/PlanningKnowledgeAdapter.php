<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Planning\Adapter;

use Aep\Application\Knowledge\Service\KnowledgeQueryService;
use Aep\Application\Planning\Model\Program;

/**
 * Optional knowledge assist for program planning — consumes Knowledge ports only.
 */
final class PlanningKnowledgeAdapter
{
    public function __construct(private readonly ?KnowledgeQueryService $knowledge = null)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function hintsFor(Program $program): array
    {
        if ($this->knowledge === null) {
            return [];
        }
        try {
            $pack = $this->knowledge->retrievePreview([
                'objective' => $program->objective(),
                'projectId' => $program->projectId(),
                'mode' => 'preview',
                'limit' => 8,
            ]);
            $hits = is_array($pack['hits'] ?? null) ? $pack['hits'] : [];
            $out = [];
            foreach ($hits as $hit) {
                if (is_array($hit)) {
                    $out[] = $hit;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
