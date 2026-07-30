<?php

declare(strict_types=1);

/**
 * ORCH-R3 lightweight autoloader for Aep\ production code and Tests\ support.
 */
spl_autoload_register(static function (string $class): void {
    $roots = [
        'Aep\\' => dirname(__DIR__) . '/src/',
        'Tests\\' => dirname(__DIR__) . '/tests/',
    ];

    foreach ($roots as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));

        if ($prefix === 'Aep\\') {
            $eventClasses = [
                'Domain\\Mission\\MissionDomainEvent',
                'Domain\\Mission\\MissionCreated',
                'Domain\\Mission\\InspectionStarted',
                'Domain\\Mission\\InspectionSubmitted',
                'Domain\\Mission\\InspectionApprovalGranted',
                'Domain\\Mission\\InspectionApprovalRejected',
                'Domain\\Mission\\ImplementationStarted',
                'Domain\\Mission\\ImplementationFinished',
                'Domain\\Mission\\ValidationStarted',
                'Domain\\Mission\\ValidationPassed',
                'Domain\\Mission\\ValidationFailed',
                'Domain\\Mission\\CorrectionLoopEntered',
                'Domain\\Mission\\CorrectionLoopExited',
                'Domain\\Mission\\CommitApprovalGranted',
                'Domain\\Mission\\CommitApprovalRejected',
                'Domain\\Mission\\CommitPhaseStarted',
                'Domain\\Mission\\PullRequestMarkedReady',
                'Domain\\Mission\\MissionCompleted',
                'Domain\\Mission\\TransitionRejected',
            ];
            if (in_array($relative, $eventClasses, true)) {
                require_once $baseDir . 'Domain/Mission/MissionEvents.php';

                return;
            }
        }

        $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }

        return;
    }
});
