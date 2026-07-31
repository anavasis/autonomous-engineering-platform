<?php

declare(strict_types=1);

namespace Aep\Application\Workflow;

use Aep\Application\MissionEngine\MissionPlan;

/**
 * Compiles WorkflowDefinition + bound params into a MissionPlan.
 */
final class WorkflowCompiler
{
    public function __construct(
        private readonly WorkflowCatalog $catalog = new WorkflowCatalog(),
        private readonly WorkflowBinder $binder = new WorkflowBinder(),
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{plan: MissionPlan, attributes: array<string, mixed>}
     */
    public function compile(WorkflowDefinition $definition, array $params): array
    {
        $steps = [];
        $attributes = [];

        foreach ($definition->tasks() as $task) {
            $when = $task->when();
            if ($when !== null && !$this->binder->evaluateWhen($when, $params)) {
                $steps[] = new SkippingStep(
                    $task->id(),
                    $task->name() ?? ('Skipped: ' . $task->id())
                );
                continue;
            }

            $with = $this->binder->resolveMap($task->with(), $params);
            $steps[] = $this->catalog->createStep($task, $with);
            foreach ($this->catalog->attributesFromWith($task->type(), $with) as $key => $value) {
                $attributes[$key] = $value;
            }
        }

        // Bound params are also available as attributes for steps that read them directly.
        foreach ($params as $key => $value) {
            if (!array_key_exists($key, $attributes)) {
                $attributes[$key] = $value;
            }
        }

        return [
            'plan' => new MissionPlan($steps),
            'attributes' => $attributes,
        ];
    }
}
