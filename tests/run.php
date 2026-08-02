<?php

declare(strict_types=1);

/**
 * Native verification runner (ORCH-R3 + ORCH-R4 persistence tests).
 *
 * Usage: php tests/run.php
 */
require_once __DIR__ . '/bootstrap.php';

$testFiles = [
    __DIR__ . '/Domain/MissionLifecycleTest.php',
    __DIR__ . '/Domain/MissionInvariantsTest.php',
    __DIR__ . '/Domain/MissionEventsTest.php',
    __DIR__ . '/Domain/ProjectLifecycleTest.php',
    __DIR__ . '/Application/MissionCommandServiceTest.php',
    __DIR__ . '/Application/ValidationPipelineTest.php',
    __DIR__ . '/Application/ExecutionServiceTest.php',
    __DIR__ . '/Application/ProjectCommandServiceTest.php',
    __DIR__ . '/Application/GitServiceTest.php',
    __DIR__ . '/Application/MissionEngineTest.php',
    __DIR__ . '/Infrastructure/JsonFileMissionRepositoryTest.php',
    __DIR__ . '/Infrastructure/JsonFileProjectRepositoryTest.php',
    __DIR__ . '/Infrastructure/GitCliAdapterTest.php',
    __DIR__ . '/Infrastructure/MissionEngineRepositoryTest.php',
    __DIR__ . '/Infrastructure/Execution/SshExecutorTest.php',
    __DIR__ . '/Infrastructure/Execution/OpenSshCommandRunnerTest.php',
    __DIR__ . '/Application/Workflow/WorkflowDslTest.php',
    __DIR__ . '/Infrastructure/Workflow/JsonFileWorkflowLibraryTest.php',
    __DIR__ . '/Application/Artifact/ArtifactServiceTest.php',
    __DIR__ . '/Infrastructure/Artifact/FilesystemArtifactTest.php',
    __DIR__ . '/Application/MissionControl/AuthServiceTest.php',
    __DIR__ . '/Application/MissionControl/MissionControlQueryTest.php',
    __DIR__ . '/Infrastructure/MissionControl/MissionControlKernelTest.php',
    __DIR__ . '/Presentation/MissionControl/HttpKernelSmokeTest.php',
    __DIR__ . '/Application/MissionExecution/HeuristicUnderstandingTest.php',
    __DIR__ . '/Application/MissionExecution/ClarificationEngineTest.php',
    __DIR__ . '/Application/MissionExecution/AutonomousMissionServiceTest.php',
    __DIR__ . '/Application/ExecutionRuntime/MissionExecutionRuntimeTest.php',
    __DIR__ . '/Infrastructure/ExecutionRuntime/FilesystemJobQueueTest.php',
    __DIR__ . '/Application/EngineeringExecution/EngineeringExecutionOrchestratorTest.php',
    __DIR__ . '/Infrastructure/EngineeringExecution/ProviderRoutingExecutorTest.php',
    __DIR__ . '/Application/EngineeringWorkspace/EngineeringWorkspaceServiceTest.php',
    __DIR__ . '/Infrastructure/EngineeringWorkspace/FilesystemEngineeringWorkspaceStoreTest.php',
    __DIR__ . '/Application/CodeReview/PatchPipelineServiceTest.php',
    __DIR__ . '/Infrastructure/CodeReview/FilesystemPatchStoreTest.php',
    __DIR__ . '/Application/Knowledge/KnowledgePipelineServiceTest.php',
    __DIR__ . '/Infrastructure/Knowledge/FilesystemKnowledgeStoreTest.php',
    __DIR__ . '/Application/Planning/PlanningPipelineServiceTest.php',
    __DIR__ . '/Infrastructure/Planning/FilesystemProgramStoreTest.php',
    __DIR__ . '/Application/Agent/AgentCoordinatorTest.php',
    __DIR__ . '/Infrastructure/Agent/FilesystemAgentStoreTest.php',
    __DIR__ . '/Infrastructure/Agent/AgentAssignmentAdapterTest.php',
    __DIR__ . '/Application/Optimization/OptimizationEngineTest.php',
    __DIR__ . '/Infrastructure/Optimization/FilesystemOptimizationStoreTest.php',
    __DIR__ . '/Infrastructure/Optimization/OptimizationAdaptersTest.php',
    __DIR__ . '/Application/Governance/ReleaseManagerTest.php',
    __DIR__ . '/Infrastructure/Governance/FilesystemGovernanceStoreTest.php',
    __DIR__ . '/Infrastructure/Governance/GovernanceAdaptersTest.php',
];

$passed = 0;
$failed = 0;
$failures = [];

foreach ($testFiles as $file) {
    require_once $file;
    $relative = substr($file, strlen(__DIR__ . '/'));
    if (!is_string($relative) || !str_ends_with($relative, '.php')) {
        throw new RuntimeException('Invalid test file path: ' . $file);
    }
    $class = 'Tests\\' . str_replace('/', '\\', substr($relative, 0, -4));

    $instance = new $class();
    $methods = get_class_methods($instance);
    sort($methods);

    foreach ($methods as $method) {
        if (!str_starts_with($method, 'test_')) {
            continue;
        }
        $label = $class . '::' . $method;
        try {
            $instance->{$method}();
            echo 'PASS  ' . $label . PHP_EOL;
            $passed++;
        } catch (Throwable $e) {
            echo 'FAIL  ' . $label . PHP_EOL;
            echo '      ' . $e->getMessage() . PHP_EOL;
            $failed++;
            $failures[] = $label . ' — ' . $e->getMessage();
        }
    }
}

echo PHP_EOL;
echo 'Passed: ' . $passed . PHP_EOL;
echo 'Failed: ' . $failed . PHP_EOL;

if ($failed > 0) {
    echo PHP_EOL . 'Failures:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'Verification suite OK' . PHP_EOL;
exit(0);
