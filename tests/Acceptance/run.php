#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Acceptance framework verification runner.
 *
 * Usage: php tests/Acceptance/run.php
 */
require_once dirname(__DIR__) . '/bootstrap.php';

$testFiles = [
    __DIR__ . '/AcceptanceFrameworkTest.php',
];

$passed = 0;
$failed = 0;
$failures = [];

foreach ($testFiles as $file) {
    require_once $file;
    $relative = substr($file, strlen(dirname(__DIR__) . '/'));
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
echo 'Acceptance suite OK' . PHP_EOL;
exit(0);
