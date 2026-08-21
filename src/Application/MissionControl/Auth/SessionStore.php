<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Auth;

interface SessionStore
{
    public function save(SessionRecord $session): void;

    public function find(string $sessionId): ?SessionRecord;

    public function delete(string $sessionId): void;
}
