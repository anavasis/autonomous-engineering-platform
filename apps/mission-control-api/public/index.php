<?php

declare(strict_types=1);

/**
 * AEP Mission Control API entrypoint.
 *
 * Version is resolved at runtime (see aep_resolve_runtime_version).
 */

require_once dirname(__DIR__, 3) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/src/HttpKernel.php';

use Aep\Apps\MissionControlApi\HttpKernel;
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

$dataRoot = getenv('AEP_DATA_ROOT') ?: (dirname(__DIR__, 3) . '/var/data');
$version = aep_resolve_runtime_version(dirname(__DIR__, 3));

$kernel = new MissionControlKernel($dataRoot, $version);
$http = new HttpKernel($kernel);
$http->handle();
