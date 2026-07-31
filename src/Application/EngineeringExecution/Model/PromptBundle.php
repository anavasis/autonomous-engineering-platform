<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Model;

final class PromptBundle
{
    /**
     * @param list<string> $attachments
     */
    public function __construct(
        private string $system,
        private string $user,
        private array $attachments = [],
        private string $hash = '',
    ) {
        if ($this->hash === '') {
            $this->hash = 'sha256:' . hash('sha256', $this->system . "\n---\n" . $this->user);
        }
    }

    public function system(): string
    {
        return $this->system;
    }

    public function user(): string
    {
        return $this->user;
    }

    /** @return list<string> */
    public function attachments(): array
    {
        return $this->attachments;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'system' => $this->system,
            'user' => $this->user,
            'attachments' => $this->attachments,
            'hash' => $this->hash,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $attachments = [];
        if (isset($data['attachments']) && is_array($data['attachments'])) {
            foreach ($data['attachments'] as $a) {
                if (is_string($a)) {
                    $attachments[] = $a;
                }
            }
        }

        return new self(
            is_string($data['system'] ?? null) ? $data['system'] : '',
            is_string($data['user'] ?? null) ? $data['user'] : '',
            $attachments,
            is_string($data['hash'] ?? null) ? $data['hash'] : '',
        );
    }
}
