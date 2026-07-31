<?php

declare(strict_types=1);

namespace Aep\Application\Project\Command;

final class CreateProject
{
    public function __construct(
        private string $projectId,
        private string $slug,
        private string $displayName,
        private string $actorType,
        private string $actorId,
        private string $occurredAtUtc,
        private string $description = ''
    ) {
        $this->projectId = self::req($projectId, 'projectId');
        $this->slug = self::req($slug, 'slug');
        $this->displayName = self::req($displayName, 'displayName');
        $this->actorType = self::req($actorType, 'actorType');
        $this->actorId = self::req($actorId, 'actorId');
        $this->occurredAtUtc = self::req($occurredAtUtc, 'occurredAtUtc');
        $this->description = trim($description);
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function description(): string
    {
        return $this->description;
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

    private static function req(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' must be non-empty.');
        }

        return $value;
    }
}
