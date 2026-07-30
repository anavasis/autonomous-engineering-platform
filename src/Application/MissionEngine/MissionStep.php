<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

/**
 * Single sequential unit of MissionEngine work.
 */
interface MissionStep
{
    public function id(): string;

    public function name(): string;

    public function execute(MissionContext $context): StepResult;
}
