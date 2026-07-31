<?php

declare(strict_types=1);

/**
 * AEP Mission Control API entrypoint.
 *
 * Version: 0.1.0
 */

require_once dirname(__DIR__, 3) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/src/HttpKernel.php';

use Aep\Apps\MissionControlApi\HttpKernel;
use Aep\Infrastructure\MissionControl\MissionControlKernel;

$dataRoot = getenv('AEP_DATA_ROOT') ?: (dirname(__DIR__, 3) . '/var/data');
$version = getenv('AEP_VERSION') ?: '0.9.0';

$kernel = new MissionControlKernel($dataRoot, $version);
$http = new HttpKernel($kernel);
$http->handle();
