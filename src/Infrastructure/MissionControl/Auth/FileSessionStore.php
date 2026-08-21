<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionControl\Auth;

use Aep\Application\MissionControl\Auth\SessionRecord;
use Aep\Application\MissionControl\Auth\SessionStore;

final class FileSessionStore implements SessionStore
{
    public function __construct(
        private string $directory
    ) {
        $this->directory = rtrim($directory, "/\\");
    }

    public function save(SessionRecord $session): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create session directory.');
        }
        $path = $this->pathFor($session->sessionId());
        $payload = json_encode($session->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temp, $payload . "\n") === false) {
            throw new \RuntimeException('Unable to write session file.');
        }
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to replace session file.');
        }
    }

    public function find(string $sessionId): ?SessionRecord
    {
        $path = $this->pathFor($sessionId);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        return SessionRecord::fromArray($data);
    }

    public function delete(string $sessionId): void
    {
        $path = $this->pathFor($sessionId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function pathFor(string $sessionId): string
    {
        if ($sessionId === '' || preg_match('/[^a-f0-9]/', $sessionId) === 1) {
            throw new \InvalidArgumentException('Invalid session id.');
        }

        return $this->directory . DIRECTORY_SEPARATOR . $sessionId . '.json';
    }
}
