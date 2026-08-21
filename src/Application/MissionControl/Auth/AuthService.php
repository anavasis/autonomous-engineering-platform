<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Auth;

/**
 * Mission Control authentication and session façade.
 */
final class AuthService
{
    public const COOKIE_NAME = 'aep_session';
    public const DEFAULT_TTL_SECONDS = 43200;

    public function __construct(
        private readonly UserStore $users,
        private readonly SessionStore $sessions,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
        if ($this->ttlSeconds < 60) {
            throw new \InvalidArgumentException('Session TTL must be at least 60 seconds.');
        }
    }

    public function ensureBootstrapAdmin(
        string $username,
        string $password,
        string $displayName,
        string $occurredAtUtc,
    ): ?User {
        if ($this->users->count() > 0) {
            return null;
        }

        $user = new User(
            'user_' . bin2hex(random_bytes(8)),
            $username,
            $displayName,
            password_hash($password, PASSWORD_ARGON2ID),
            new Role(Role::ADMIN),
            $occurredAtUtc,
            true
        );
        $this->users->save($user);

        return $user;
    }

    /**
     * @return array{user: User, session: SessionRecord}
     */
    public function login(string $username, string $password, string $occurredAtUtc): array
    {
        $user = $this->users->findByUsername($username);
        if ($user === null || !$user->isActive()) {
            throw new AuthException('Invalid username or password.', 401);
        }
        if (!password_verify($password, $user->passwordHash())) {
            throw new AuthException('Invalid username or password.', 401);
        }

        $session = new SessionRecord(
            bin2hex(random_bytes(32)),
            $user->id(),
            bin2hex(random_bytes(32)),
            $occurredAtUtc,
            $this->expiryFrom($occurredAtUtc),
            $occurredAtUtc
        );
        $this->sessions->save($session);

        return ['user' => $user, 'session' => $session];
    }

    public function logout(string $sessionId): void
    {
        $this->sessions->delete($sessionId);
    }

    public function resolveSession(string $sessionId, string $nowUtc): ?User
    {
        $session = $this->sessions->find($sessionId);
        if ($session === null) {
            return null;
        }
        if (strcmp($session->expiresAtUtc(), $nowUtc) < 0) {
            $this->sessions->delete($sessionId);

            return null;
        }

        $user = $this->users->findById($session->userId());
        if ($user === null || !$user->isActive()) {
            $this->sessions->delete($sessionId);

            return null;
        }

        $this->sessions->save($session->withLastSeen($nowUtc));

        return $user;
    }

    public function session(string $sessionId): ?SessionRecord
    {
        return $this->sessions->find($sessionId);
    }

    public function assertRole(User $user, string $minimumRole): void
    {
        if (!$user->role()->atLeast($minimumRole)) {
            throw new AuthException('Insufficient permissions.', 403);
        }
    }

    private function expiryFrom(string $occurredAtUtc): string
    {
        $ts = strtotime($occurredAtUtc);
        if ($ts === false) {
            $ts = time();
        }

        return gmdate('Y-m-d\TH:i:s\Z', $ts + $this->ttlSeconds);
    }
}
