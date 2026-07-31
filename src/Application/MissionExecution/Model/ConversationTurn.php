<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Model;

final class ConversationTurn
{
    /** @param array<string, mixed>|null $intentPatch */
    public function __construct(
        private string $role,
        private string $text,
        private string $atUtc,
        private ?array $intentPatch = null,
    ) {
        if (!in_array($role, ['user', 'system', 'assistant'], true)) {
            throw new \InvalidArgumentException('Invalid conversation role.');
        }
        $this->text = trim($text);
        $this->atUtc = trim($atUtc);
        if ($this->text === '' || $this->atUtc === '') {
            throw new \InvalidArgumentException('Conversation turn text and atUtc are required.');
        }
    }

    public function role(): string
    {
        return $this->role;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function atUtc(): string
    {
        return $this->atUtc;
    }

    /** @return array<string, mixed>|null */
    public function intentPatch(): ?array
    {
        return $this->intentPatch;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'text' => $this->text,
            'atUtc' => $this->atUtc,
            'intentPatch' => $this->intentPatch,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $patch = isset($data['intentPatch']) && is_array($data['intentPatch']) ? $data['intentPatch'] : null;

        return new self(
            is_string($data['role'] ?? null) ? $data['role'] : 'user',
            is_string($data['text'] ?? null) ? $data['text'] : '',
            is_string($data['atUtc'] ?? null) ? $data['atUtc'] : '',
            $patch
        );
    }
}
