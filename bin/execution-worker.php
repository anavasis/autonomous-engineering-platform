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

if (!function_exists('aep_resolve_runtime_version')) {
    /**
     * Precedence: non-empty AEP_VERSION env → VERSION file → safe fallback.
     */
    function aep_resolve_runtime_version(string $repoRoot): string
    {
        $env = getenv('AEP_VERSION');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        $versionFile = rtrim($repoRoot, "/\\") . '/VERSION';
        if (is_file($versionFile)) {
            $fromFile = trim((string) file_get_contents($versionFile));
            if ($fromFile !== '') {
                return $fromFile;
            }
        }

        return '0.0.0';
    }
}

if (defined('AEP_TEST_RESOLVER_ONLY') && AEP_TEST_RESOLVER_ONLY) {
    return;
}

$dataRoot = getenv('AEP_DATA_ROOT');
if (!is_string($dataRoot) || trim($dataRoot) === '') {
    $dataRoot = $root . '/var/aep-data';
}
$version = aep_resolve_runtime_version($root);

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
