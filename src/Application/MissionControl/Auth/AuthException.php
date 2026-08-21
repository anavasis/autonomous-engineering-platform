<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Auth;

final class AuthException extends \RuntimeException
{
    public function __construct(
        string $message,
        private int $statusCode = 401,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
