<?php

declare(strict_types=1);

namespace Aep\Application\Validation;

/**
 * Input for the validation pipeline.
 *
 * @phpstan-type ContextMap array<string, mixed>
 */
final class ValidationRequest
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $missionId,
        private string $occurredAtUtc,
        private array $context = []
    ) {
        $this->missionId = self::requireNonEmpty($missionId, 'missionId');
        $this->occurredAtUtc = self::requireNonEmpty($occurredAtUtc, 'occurredAtUtc');
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAtUtc;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function contextValue(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    private static function requireNonEmpty(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' must be non-empty.');
        }

        return $value;
    }
}
