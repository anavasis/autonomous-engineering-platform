<?php

declare(strict_types=1);

namespace Aep\Application\Workflow;

use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionPlan;
use Aep\Application\MissionEngine\MissionPlanFactory;

/**
 * Compiles a library workflow into a MissionPlan for MissionEngine.
 */
final class WorkflowBackedMissionPlanFactory implements MissionPlanFactory
{
    public const DEFAULT_WORKFLOW_ID = 'aep.default_mission';
    public const DEFAULT_WORKFLOW_VERSION = '1.0.0';

    /** @var array<string, mixed> */
    private array $runAttributes = [];

    public function __construct(
        private readonly WorkflowLibrary $library,
        private readonly WorkflowValidator $validator = new WorkflowValidator(),
        private readonly WorkflowCompiler $compiler = new WorkflowCompiler(),
        private readonly WorkflowBinder $binder = new WorkflowBinder(),
        private readonly string $defaultWorkflowId = self::DEFAULT_WORKFLOW_ID,
        private readonly string $defaultWorkflowVersion = self::DEFAULT_WORKFLOW_VERSION,
    ) {
    }

    public function build(MissionContext $context): MissionPlan
    {
        $workflowId = $context->attribute('workflowId', $this->defaultWorkflowId);
        $workflowVersion = $context->attribute('workflowVersion', $this->defaultWorkflowVersion);
        if (!is_string($workflowId) || trim($workflowId) === '') {
            $workflowId = $this->defaultWorkflowId;
        }
        if (!is_string($workflowVersion) || trim($workflowVersion) === '') {
            $workflowVersion = $this->defaultWorkflowVersion;
        }

        $definition = $this->library->get($workflowId, $workflowVersion);
        $validation = $this->validator->validate($definition);
        if (!$validation->isValid()) {
            throw new \InvalidArgumentException(
                'Invalid workflow: ' . implode('; ', $validation->errors())
            );
        }

        $params = $this->binder->bindParameters($definition, $context->attributes());
        $compiled = $this->compiler->compile($definition, $params);

        $hash = $definition->sourceHash();
        if ($hash === '') {
            $hash = hash('sha256', $definition->workflow() . '@' . $definition->version());
        }

        $this->runAttributes = array_merge($compiled['attributes'], [
            'workflowId' => $definition->workflow(),
            'workflowVersion' => $definition->version(),
            'workflowHash' => $hash,
        ]);

        return $compiled['plan'];
    }

    public function runAttributes(): array
    {
        return $this->runAttributes;
    }
}
