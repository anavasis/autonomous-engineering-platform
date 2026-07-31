<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

/**
 * Builds a sequential MissionPlan for MissionEngine.
 *
 * Runtime drive loop remains owned by MissionEngine.
 */
interface MissionPlanFactory
{
    public function build(MissionContext $context): MissionPlan;

    /**
     * Attributes to merge into the run after build (workflow metadata, bound params).
     *
     * @return array<string, mixed>
     */
    public function runAttributes(): array;
}
