<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

/**
 * Ordered mission-engine timeline (newest last).
 */
final class MissionTimeline
{
    /** @var list<MissionTimelineEntry> */
    private array $entries;

    /**
     * @param list<MissionTimelineEntry> $entries
     */
    public function __construct(array $entries = [])
    {
        foreach ($entries as $entry) {
            if (!$entry instanceof MissionTimelineEntry) {
                throw new \InvalidArgumentException('MissionTimeline entries must be MissionTimelineEntry.');
            }
        }
        $this->entries = $entries;
    }

    public function append(MissionTimelineEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return list<MissionTimelineEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (MissionTimelineEntry $e) => $e->toArray(), $this->entries);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function fromArray(array $rows): self
    {
        $entries = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $entries[] = MissionTimelineEntry::fromArray($row);
            }
        }

        return new self($entries);
    }
}
