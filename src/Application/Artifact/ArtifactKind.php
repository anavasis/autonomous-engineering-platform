<?php

declare(strict_types=1);

namespace Aep\Application\Artifact;

/**
 * Fixed artifact kind catalog.
 */
final class ArtifactKind
{
    public const LOG = 'log';
    public const REPORT = 'report';
    public const VALIDATION = 'validation';
    public const CHECKPOINT = 'checkpoint';
    public const FILE = 'file';
    public const ARCHIVE = 'archive';

    private const ALLOWED = [
        self::LOG,
        self::REPORT,
        self::VALIDATION,
        self::CHECKPOINT,
        self::FILE,
        self::ARCHIVE,
    ];

    public function __construct(
        private string $value
    ) {
        if (!in_array($value, self::ALLOWED, true)) {
            throw new \InvalidArgumentException('Invalid ArtifactKind: ' . $value);
        }
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function is(string $kind): bool
    {
        return $this->value === $kind;
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALLOWED;
    }

    public static function directoryName(string $kind): string
    {
        return match ($kind) {
            self::LOG => 'logs',
            self::REPORT => 'reports',
            self::VALIDATION => 'validation',
            self::CHECKPOINT => 'checkpoints',
            self::FILE => 'files',
            self::ARCHIVE => 'archives',
            default => throw new \InvalidArgumentException('Invalid ArtifactKind: ' . $kind),
        };
    }
}
