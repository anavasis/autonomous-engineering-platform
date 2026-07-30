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
    __DIR__ . '/Application/MissionCommandServiceTest.php',
    __DIR__ . '/Infrastructure/JsonFileMissionRepositoryTest.php',
];

$passed = 0;
$failed = 0;
$failures = [];

foreach ($testFiles as $file) {
    require_once $file;
    $base = basename($file, '.php');
    if (str_contains($file, '/Application/')) {
        $class = 'Tests\\Application\\' . $base;
    } elseif (str_contains($file, '/Infrastructure/')) {
        $class = 'Tests\\Infrastructure\\' . $base;
    } else {
        $class = 'Tests\\Domain\\' . $base;
    }

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
