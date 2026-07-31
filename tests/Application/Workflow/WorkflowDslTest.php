<?php

declare(strict_types=1);

namespace Tests\Application\Workflow;

use Aep\Application\Execution\ExecutionService;
use Aep\Application\Mission\Command\CreateMission;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionEngine\DefaultMissionPlanFactory;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionEngine;
use Aep\Application\MissionEngine\MissionEngineRequest;
use Aep\Application\MissionEngine\CancellationToken;
use Aep\Application\MissionEngine\RetryPolicy;
use Aep\Application\MissionEngine\TimeoutPolicy;
use Aep\Application\Validation\ValidationPipeline;
use Aep\Application\Workflow\DefaultMissionWorkflow;
use Aep\Application\Workflow\InMemoryWorkflowLibrary;
use Aep\Application\Workflow\WorkflowBackedMissionPlanFactory;
use Aep\Application\Workflow\WorkflowBinder;
use Aep\Application\Workflow\WorkflowCatalog;
use Aep\Application\Workflow\WorkflowCompiler;
use Aep\Application\Workflow\WorkflowParser;
use Aep\Application\Workflow\WorkflowValidator;
use Aep\Infrastructure\Execution\DeclarativeLocalExecutor;
use Aep\Infrastructure\MissionEngine\InMemoryMissionRunRepository;
use Aep\Infrastructure\Persistence\InMemoryMissionRepository;
use Aep\Infrastructure\Validation\DeclarativeContextValidationStep;
use Tests\Support\Assert;

/**
 * ORCH-R11 Workflow & Task DSL tests.
 */
final class WorkflowDslTest
{
    public function test_parser(): void
    {
        $definition = (new WorkflowParser())->parse(DefaultMissionWorkflow::json());
        Assert::same('aep.default_mission', $definition->workflow());
        Assert::same('1.0.0', $definition->version());
        Assert::same(12, count($definition->tasks()));
        Assert::true($definition->sourceHash() !== '');
        Assert::same('Default mission workflow', $definition->metadata()['title'] ?? null);
    }

    public function test_parser_rejects_unknown_root_key(): void
    {
        Assert::throws(\InvalidArgumentException::class, static function (): void {
            (new WorkflowParser())->parse('{"workflow":"x","version":"1.0.0","tasks":[{"id":"a","type":"mission.complete"}],"yaml":true}');
        });
    }

    public function test_validator(): void
    {
        $definition = (new WorkflowParser())->parse(DefaultMissionWorkflow::json());
        $result = (new WorkflowValidator())->validate($definition);
        Assert::true($result->isValid());

        $bad = (new WorkflowParser())->parse(json_encode([
            'workflow' => 'bad',
            'version' => '1.0.0',
            'tasks' => [
                ['id' => 'a', 'type' => 'not.a.real.type'],
            ],
        ], JSON_THROW_ON_ERROR));
        $invalid = (new WorkflowValidator())->validate($bad);
        Assert::true(!$invalid->isValid());
        Assert::true(count($invalid->errors()) >= 1);
    }

    public function test_compiler_equals_legacy_default_plan(): void
    {
        $library = new InMemoryWorkflowLibrary();
        DefaultMissionWorkflow::register($library);
        $factory = new WorkflowBackedMissionPlanFactory($library);
        $legacy = new DefaultMissionPlanFactory();

        $context = $this->context([]);
        $workflowPlan = $factory->build($context);
        $legacyPlan = $legacy->build($context);

        Assert::same($legacyPlan->stepIds(), $workflowPlan->stepIds());
    }

    public function test_parameter_binding(): void
    {
        $definition = (new WorkflowParser())->parse(DefaultMissionWorkflow::json());
        $binder = new WorkflowBinder();
        $params = $binder->bindParameters($definition, [
            'allowedPaths' => ['src/Application/Workflow/'],
            'executionAction' => 'implement',
        ]);
        Assert::same(['src/Application/Workflow/'], $params['allowedPaths']);
        Assert::same('implement', $params['executionAction']);

        $compiled = (new WorkflowCompiler())->compile($definition, $params);
        Assert::same(['src/Application/Workflow/'], $compiled['attributes']['allowedPaths'] ?? null);
        Assert::same('implement', $compiled['attributes']['executionAction'] ?? null);
    }

    public function test_conditional_skip(): void
    {
        $json = json_encode([
            'workflow' => 'aep.skip_demo',
            'version' => '1.0.0',
            'metadata' => [],
            'parameters' => [
                ['name' => 'includeExtra', 'type' => 'bool', 'default' => false],
            ],
            'tasks' => [
                ['id' => 'start_inspection', 'type' => 'mission.start_inspection'],
                [
                    'id' => 'extra_gate',
                    'type' => 'gate.manual',
                    'with' => ['gateId' => 'extra'],
                    'when' => ['eq' => ['${params.includeExtra}', true]],
                ],
                ['id' => 'submit_inspection', 'type' => 'mission.submit_inspection'],
            ],
        ], JSON_THROW_ON_ERROR);

        $definition = (new WorkflowParser())->parse($json);
        Assert::true((new WorkflowValidator())->validate($definition)->isValid());

        $compiler = new WorkflowCompiler();
        $skipped = $compiler->compile($definition, ['includeExtra' => false]);
        Assert::same(['start_inspection', 'extra_gate', 'submit_inspection'], $skipped['plan']->stepIds());

        $ctx = $this->context([]);
        // SkippingStep should succeed without needing gate attr.
        $result = $skipped['plan']->stepAt(1)->execute($ctx);
        Assert::true($result->isSucceeded());
        Assert::same(true, $result->context()['skipped'] ?? null);

        $included = $compiler->compile($definition, ['includeExtra' => true]);
        Assert::same('extra', $included['plan']->stepAt(1)->id()); // ManualGateStep id is gateId
    }

    public function test_workflow_version_persistence(): void
    {
        $library = new InMemoryWorkflowLibrary();
        DefaultMissionWorkflow::register($library);
        $factory = new WorkflowBackedMissionPlanFactory($library);

        $missionRepo = new InMemoryMissionRepository();
        $missions = new MissionCommandService($missionRepo);
        $runs = new InMemoryMissionRunRepository();
        $engine = new MissionEngine(
            $missions,
            $missionRepo,
            $runs,
            $factory,
            new ExecutionService(new DeclarativeLocalExecutor()),
            new ValidationPipeline([new DeclarativeContextValidationStep()])
        );

        $missions->create(new CreateMission(
            'msn_wf_meta',
            'github',
            'anavasis/example-target',
            'workflow metadata',
            'user',
            'tester-1',
            '2026-07-30T12:00:00Z'
        ));

        $engine->start(new MissionEngineRequest(
            'run_wf_meta',
            'msn_wf_meta',
            '2026-07-30T12:00:00Z',
            'user',
            'tester-1',
            [
                'allowedPaths' => ['src/'],
            ],
            null,
            new RetryPolicy(1, 0),
            TimeoutPolicy::disabled()
        ));

        $checkpoint = $runs->getCheckpoint('run_wf_meta');
        Assert::same('aep.default_mission', $checkpoint->attributes()['workflowId'] ?? null);
        Assert::same('1.0.0', $checkpoint->attributes()['workflowVersion'] ?? null);
        Assert::true(is_string($checkpoint->attributes()['workflowHash'] ?? null));
        Assert::true(strlen((string) $checkpoint->attributes()['workflowHash']) === 64);
    }

    public function test_catalog_stable_identifiers(): void
    {
        $catalog = new WorkflowCatalog();
        Assert::contains(WorkflowCatalog::START_INSPECTION, $catalog->types());
        Assert::contains(WorkflowCatalog::SUBMIT_INSPECTION, $catalog->types());
        Assert::contains(WorkflowCatalog::EXECUTION_RUN, $catalog->types());
        Assert::contains(WorkflowCatalog::VALIDATION_RUN, $catalog->types());
        Assert::contains(WorkflowCatalog::GATE_MANUAL, $catalog->types());
        Assert::contains(WorkflowCatalog::COMPLETE, $catalog->types());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function context(array $attributes): MissionContext
    {
        $missions = new MissionCommandService(new InMemoryMissionRepository());

        return new MissionContext(
            'run_ctx',
            'msn_ctx',
            '2026-07-30T12:00:00Z',
            'user',
            'tester-1',
            $missions,
            new CancellationToken(),
            $attributes,
        );
    }
}
