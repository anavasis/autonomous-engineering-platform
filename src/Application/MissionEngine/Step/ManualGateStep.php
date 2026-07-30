<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine\Step;

use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;

/**
 * Generic manual gate. Suspends the run until an approval decision is present.
 *
 * Attributes:
 * - gate.{id} = "approved" | "rejected"
 * Missing / unknown → waiting.
 */
final class ManualGateStep implements MissionStep
{
    public function __construct(
        private string $id,
        private string $name = 'Manual gate'
    ) {
        $this->id = trim($id);
        if ($this->id === '') {
            throw new \InvalidArgumentException('ManualGateStep id must be non-empty.');
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
        if ($context->cancellation()->isCancelled()) {
            return StepResult::cancelled($context->cancellation()->reason());
        }

        $key = 'gate.' . $this->id;
        $decision = $context->attribute($key);
        if (!is_string($decision) || trim($decision) === '') {
            return StepResult::waiting('Manual gate pending: ' . $this->id, ['gate' => $this->id]);
        }

        $decision = strtolower(trim($decision));
        if ($decision === 'approved') {
            return StepResult::succeeded('Manual gate approved: ' . $this->id, ['gate' => $this->id]);
        }
        if ($decision === 'rejected') {
            return StepResult::rejected('Manual gate rejected: ' . $this->id, ['gate' => $this->id]);
        }

        return StepResult::waiting('Manual gate pending: ' . $this->id, ['gate' => $this->id]);
    }
}
