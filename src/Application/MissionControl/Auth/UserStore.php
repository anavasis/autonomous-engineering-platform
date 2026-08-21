<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Auth;

interface UserStore
{
    public function save(User $user): void;

    public function findById(string $id): ?User;

    public function findByUsername(string $username): ?User;

    public function count(): int;

    /**
     * @return list<User>
     */
    public function all(): array;
}
