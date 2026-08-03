<?php

declare(strict_types=1);

namespace Aep\Application\Acceptance\Model;

final class ValidationOutcome
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        private string $ruleId,
        private string $type,
        private bool $passed,
        private string $message,
        private array $details = [],
    ) {
    }

    public function ruleId(): string
    {
        return $this->ruleId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function message(): string
    {
        return $this->message;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ruleId' => $this->ruleId,
            'type' => $this->type,
            'passed' => $this->passed,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
