<?php

declare(strict_types=1);

namespace Aep\Application\Mission\Command;

/**
 * Create a new Mission.
 */
final class CreateMission
{
    public function __construct(
        private string $missionId,
        private string $provider,
        private string $repository,
        private string $objective,
        private string $actorType,
        private string $actorId,
        private string $occurredAtUtc
    ) {
        $this->missionId = self::requireNonEmpty($missionId, 'missionId');
        $this->provider = self::requireNonEmpty($provider, 'provider');
        $this->repository = self::requireNonEmpty($repository, 'repository');
        $this->objective = self::requireNonEmpty($objective, 'objective');
        $this->actorType = self::requireNonEmpty($actorType, 'actorType');
        $this->actorId = self::requireNonEmpty($actorId, 'actorId');
        $this->occurredAtUtc = self::requireNonEmpty($occurredAtUtc, 'occurredAtUtc');
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function repository(): string
    {
        return $this->repository;
    }

    public function objective(): string
    {
        return $this->objective;
    }

    public function actorType(): string
    {
        return $this->actorType;
    }

    public function actorId(): string
    {
        return $this->actorId;
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAtUtc;
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
