<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Policy;

use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;

/**
 * Policy-driven scheduling: admit and/or score ready nodes.
 */
interface SchedulingPolicy
{
    public function id(): string;

    /**
     * @param array<string, mixed> $context
     * @return array{admit: bool, scoreDelta: float, reason: string}
     */
    public function evaluate(Program $program, ProgramNode $node, array $context = []): array;
}
