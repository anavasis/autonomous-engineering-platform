<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Auth;

final class SessionRecord
{
    public function __construct(
        private string $sessionId,
        private string $userId,
        private string $csrfToken,
        private string $createdAtUtc,
        private string $expiresAtUtc,
        private string $lastSeenAtUtc,
    ) {
        $this->sessionId = self::requireNonEmpty($sessionId, 'sessionId');
        $this->userId = self::requireNonEmpty($userId, 'userId');
        $this->csrfToken = self::requireNonEmpty($csrfToken, 'csrfToken');
        $this->createdAtUtc = self::requireNonEmpty($createdAtUtc, 'createdAtUtc');
        $this->expiresAtUtc = self::requireNonEmpty($expiresAtUtc, 'expiresAtUtc');
        $this->lastSeenAtUtc = self::requireNonEmpty($lastSeenAtUtc, 'lastSeenAtUtc');
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function csrfToken(): string
    {
        return $this->csrfToken;
    }

    public function createdAtUtc(): string
    {
        return $this->createdAtUtc;
    }

    public function expiresAtUtc(): string
    {
        return $this->expiresAtUtc;
    }

    public function lastSeenAtUtc(): string
    {
        return $this->lastSeenAtUtc;
    }

    public function withLastSeen(string $atUtc): self
    {
        return new self(
            $this->sessionId,
            $this->userId,
            $this->csrfToken,
            $this->createdAtUtc,
            $this->expiresAtUtc,
            $atUtc
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sessionId' => $this->sessionId,
            'userId' => $this->userId,
            'csrfToken' => $this->csrfToken,
            'createdAtUtc' => $this->createdAtUtc,
            'expiresAtUtc' => $this->expiresAtUtc,
            'lastSeenAtUtc' => $this->lastSeenAtUtc,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['sessionId'] ?? null) ? $data['sessionId'] : '',
            is_string($data['userId'] ?? null) ? $data['userId'] : '',
            is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '',
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['expiresAtUtc'] ?? null) ? $data['expiresAtUtc'] : '',
            is_string($data['lastSeenAtUtc'] ?? null) ? $data['lastSeenAtUtc'] : '',
        );
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
