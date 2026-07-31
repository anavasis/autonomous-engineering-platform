<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Model;

final class Conversation
{
    /** @param list<ConversationTurn> $turns */
    public function __construct(
        private string $id,
        private array $turns = [],
        private ?string $missionId = null,
        private ?string $intakeId = null,
    ) {
        $this->id = trim($id);
        if ($this->id === '') {
            throw new \InvalidArgumentException('Conversation id is required.');
        }
        foreach ($this->turns as $turn) {
            if (!$turn instanceof ConversationTurn) {
                throw new \InvalidArgumentException('turns must be ConversationTurn instances.');
            }
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function missionId(): ?string
    {
        return $this->missionId;
    }

    public function intakeId(): ?string
    {
        return $this->intakeId;
    }

    /** @return list<ConversationTurn> */
    public function turns(): array
    {
        return $this->turns;
    }

    public function append(ConversationTurn $turn): void
    {
        $this->turns[] = $turn;
    }

    public function bindMission(string $missionId): void
    {
        $this->missionId = $missionId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'missionId' => $this->missionId,
            'intakeId' => $this->intakeId,
            'turns' => array_map(static fn (ConversationTurn $t) => $t->toArray(), $this->turns),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $turns = [];
        if (isset($data['turns']) && is_array($data['turns'])) {
            foreach ($data['turns'] as $row) {
                if (is_array($row)) {
                    $turns[] = ConversationTurn::fromArray($row);
                }
            }
        }

        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : '',
            $turns,
            isset($data['missionId']) && is_string($data['missionId']) ? $data['missionId'] : null,
            isset($data['intakeId']) && is_string($data['intakeId']) ? $data['intakeId'] : null,
        );
    }
}
