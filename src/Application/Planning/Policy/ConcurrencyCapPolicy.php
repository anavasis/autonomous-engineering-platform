<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Policy;

use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;

final class ConcurrencyCapPolicy implements SchedulingPolicy
{
    public function __construct(private readonly int $maxInFlight = 3) {}

    public function id(): string { return 'concurrency_cap'; }

    public function evaluate(Program $program, ProgramNode $node, array $context = []): array
    {
        $inFlight = is_int($context['inFlight'] ?? null) ? $context['inFlight'] : 0;
        $ok = $inFlight < $this->maxInFlight;

        return [
            'admit' => $ok,
            'scoreDelta' => $ok ? 1.0 : 0.0,
            'reason' => $ok ? 'under concurrency cap' : 'concurrency cap reached',
        ];
    }
}
