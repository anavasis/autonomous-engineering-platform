<?php

declare(strict_types=1);

namespace Aep\Application\Workflow;

use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;

/**
 * No-op step used when a workflow task `when` condition is false.
 */
final class SkippingStep implements MissionStep
{
    public function __construct(
        private string $id,
        private string $name = 'Skipped task',
    ) {
        $this->id = trim($id);
        if ($this->id === '') {
            throw new \InvalidArgumentException('SkippingStep id must be non-empty.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function execute(MissionContext $context): StepResult
    {
        unset($context);

        return StepResult::succeeded('skipped', ['skipped' => true]);
    }
}
