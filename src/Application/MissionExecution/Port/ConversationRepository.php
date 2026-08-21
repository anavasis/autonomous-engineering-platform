<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Port;

use Aep\Application\MissionExecution\Model\Conversation;

interface ConversationRepository
{
    public function save(Conversation $conversation): void;

    public function find(string $id): ?Conversation;

    public function findByMissionId(string $missionId): ?Conversation;
}
