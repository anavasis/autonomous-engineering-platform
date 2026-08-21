<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Auth;

/**
 * Mission Control operator account (presentation identity — not Domain Actor).
 */
final class User
{
    public function __construct(
        private string $id,
        private string $username,
        private string $displayName,
        private string $passwordHash,
        private Role $role,
        private string $createdAtUtc,
        private bool $active = true,
    ) {
        $this->id = self::requireNonEmpty($id, 'id');
        $this->username = strtolower(self::requireNonEmpty($username, 'username'));
        $this->displayName = self::requireNonEmpty($displayName, 'displayName');
        $this->passwordHash = self::requireNonEmpty($passwordHash, 'passwordHash');
        $this->createdAtUtc = self::requireNonEmpty($createdAtUtc, 'createdAtUtc');
    }

    public function id(): string
    {
        return $this->id;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function role(): Role
    {
        return $this->role;
    }

    public function createdAtUtc(): string
    {
        return $this->createdAtUtc;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'displayName' => $this->displayName,
            'role' => $this->role->toString(),
            'createdAtUtc' => $this->createdAtUtc,
            'active' => $this->active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'displayName' => $this->displayName,
            'passwordHash' => $this->passwordHash,
            'role' => $this->role->toString(),
            'createdAtUtc' => $this->createdAtUtc,
            'active' => $this->active,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromStorageArray(array $data): self
    {
        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : '',
            is_string($data['username'] ?? null) ? $data['username'] : '',
            is_string($data['displayName'] ?? null) ? $data['displayName'] : '',
            is_string($data['passwordHash'] ?? null) ? $data['passwordHash'] : '',
            new Role(is_string($data['role'] ?? null) ? $data['role'] : Role::VIEWER),
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            ($data['active'] ?? true) === true,
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
