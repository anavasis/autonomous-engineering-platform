<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Model;

final class ClarificationQuestion
{
    /**
     * @param list<array{value: string, label: string}> $choices
     */
    public function __construct(
        private string $id,
        private string $field,
        private string $prompt,
        private array $choices = [],
    ) {
        $this->id = trim($id);
        $this->field = trim($field);
        $this->prompt = trim($prompt);
        if ($this->id === '' || $this->field === '' || $this->prompt === '') {
            throw new \InvalidArgumentException('ClarificationQuestion requires id, field, and prompt.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function field(): string
    {
        return $this->field;
    }

    public function prompt(): string
    {
        return $this->prompt;
    }

    /** @return list<array{value: string, label: string}> */
    public function choices(): array
    {
        return $this->choices;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'field' => $this->field,
            'prompt' => $this->prompt,
            'choices' => $this->choices,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $choices = [];
        if (isset($data['choices']) && is_array($data['choices'])) {
            foreach ($data['choices'] as $choice) {
                if (is_array($choice) && is_string($choice['value'] ?? null) && is_string($choice['label'] ?? null)) {
                    $choices[] = ['value' => $choice['value'], 'label' => $choice['label']];
                }
            }
        }

        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : '',
            is_string($data['field'] ?? null) ? $data['field'] : '',
            is_string($data['prompt'] ?? null) ? $data['prompt'] : '',
            $choices
        );
    }
}
