<?php

declare(strict_types=1);

/**
 * Router script for PHP built-in server.
 * Forwards non-file API requests to public/index.php.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . '/../apps/mission-control-api/public' . $path;

if (is_string($path) && $path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/../apps/mission-control-api/public/index.php';
