<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

/**
 * Fixed ordered step plan (sequential only).
 */
final class MissionPlan
{
    /** @var list<MissionStep> */
    private array $steps;

    /**
     * @param list<MissionStep> $steps
     */
    public function __construct(array $steps)
    {
        if ($steps === []) {
            throw new \InvalidArgumentException('MissionPlan requires at least one step.');
        }
        $ids = [];
        foreach ($steps as $step) {
            if (!$step instanceof MissionStep) {
                throw new \InvalidArgumentException('MissionPlan steps must implement MissionStep.');
            }
            if (isset($ids[$step->id()])) {
                throw new \InvalidArgumentException('Duplicate MissionStep id: ' . $step->id());
            }
            $ids[$step->id()] = true;
        }
        $this->steps = array_values($steps);
    }

    /**
     * @return list<MissionStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function size(): int
    {
        return count($this->steps);
    }

    public function stepAt(int $index): MissionStep
    {
        if (!isset($this->steps[$index])) {
            throw new \OutOfBoundsException('MissionPlan index out of range: ' . $index);
        }

        return $this->steps[$index];
    }

    /**
     * @return list<string>
     */
    public function stepIds(): array
    {
        return array_map(static fn (MissionStep $s) => $s->id(), $this->steps);
    }
}
