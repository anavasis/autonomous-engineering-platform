<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionControl\Auth;

use Aep\Application\MissionControl\Auth\User;
use Aep\Application\MissionControl\Auth\UserStore;

final class JsonUserStore implements UserStore
{
    public function __construct(
        private string $filePath
    ) {
        $this->filePath = $filePath;
    }

    public function save(User $user): void
    {
        $all = $this->readAll();
        $all[$user->id()] = $user->toStorageArray();
        $this->writeAll($all);
    }

    public function findById(string $id): ?User
    {
        $all = $this->readAll();
        if (!isset($all[$id]) || !is_array($all[$id])) {
            return null;
        }

        return User::fromStorageArray($all[$id]);
    }

    public function findByUsername(string $username): ?User
    {
        $username = strtolower(trim($username));
        foreach ($this->readAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $user = User::fromStorageArray($row);
            if ($user->username() === $username) {
                return $user;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->readAll());
    }

    public function all(): array
    {
        $users = [];
        foreach ($this->readAll() as $row) {
            if (is_array($row)) {
                $users[] = User::fromStorageArray($row);
            }
        }

        return $users;
    }

    /** @return array<string, mixed> */
    private function readAll(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }
        $raw = file_get_contents($this->filePath);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid users.json', 0, $e);
        }
        if (!is_array($data)) {
            return [];
        }
        $users = $data['users'] ?? $data;
        if (!is_array($users)) {
            return [];
        }
        /** @var array<string, mixed> $users */
        return $users;
    }

    /** @param array<string, mixed> $users */
    private function writeAll(array $users): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create auth directory: ' . $dir);
        }
        $payload = json_encode(['schemaVersion' => 1, 'users' => $users], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $temp = $this->filePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temp, $payload . "\n") === false) {
            throw new \RuntimeException('Unable to write users file.');
        }
        if (!rename($temp, $this->filePath)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to replace users file.');
        }
    }
}
