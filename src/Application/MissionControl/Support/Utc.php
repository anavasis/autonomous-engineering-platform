<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Support;

final class Utc
{
    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
