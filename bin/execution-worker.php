#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Execution Runtime worker — claims and runs queued Runtime jobs.
 *
 * Usage:
 *   AEP_DATA_ROOT=/var/aep/data php bin/execution-worker.php
 */

$root = dirname(__DIR__);
require_once $root . '/tests/bootstrap.php';

use Aep\Infrastructure\MissionControl\MissionControlKernel;

$dataRoot = getenv('AEP_DATA_ROOT');
if (!is_string($dataRoot) || trim($dataRoot) === '') {
    $dataRoot = $root . '/var/aep-data';
}
$version = getenv('AEP_VERSION');
if (!is_string($version) || trim($version) === '') {
    $versionFile = $root . '/VERSION';
    $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '1.7.0';
}

$workerId = getenv('AEP_RUNTIME_WORKER_ID');
if (!is_string($workerId) || trim($workerId) === '') {
    $workerId = 'worker-' . gethostname() . '-' . getmypid();
}

$kernel = new MissionControlKernel($dataRoot, $version);
$worker = $kernel->runtimeWorker();

$stopping = false;
$stop = static function () use (&$stopping, $worker): void {
    if ($stopping) {
        return;
    }
    $stopping = true;
    $worker->requestStop();
    fwrite(STDERR, "Execution worker stop requested; finishing current job…\n");
};

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

fwrite(STDERR, 'Execution worker started: ' . $workerId . " dataRoot={$dataRoot}\n");
$worker->runForever($workerId);
fwrite(STDERR, "Execution worker stopped.\n");
