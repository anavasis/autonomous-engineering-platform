<?php

declare(strict_types=1);

/**
 * ORCH-R3 native test runner.
 *
 * Usage: php tests/run.php
 */
require_once __DIR__ . '/bootstrap.php';

$testFiles = [
    __DIR__ . '/Domain/MissionLifecycleTest.php',
    __DIR__ . '/Domain/MissionInvariantsTest.php',
    __DIR__ . '/Domain/MissionEventsTest.php',
    __DIR__ . '/Application/MissionCommandServiceTest.php',
];

$passed = 0;
$failed = 0;
$failures = [];

foreach ($testFiles as $file) {
    require_once $file;
    $base = basename($file, '.php');
    $class = str_contains($file, '/Application/')
        ? 'Tests\\Application\\' . $base
        : 'Tests\\Domain\\' . $base;

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

echo 'ORCH-R3 verification suite OK' . PHP_EOL;
exit(0);
