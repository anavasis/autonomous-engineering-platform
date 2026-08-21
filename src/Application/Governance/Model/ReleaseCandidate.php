<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Model;

/** ReleaseCandidate is a ReleaseRecord in candidate status with evidence bindings. */
final class ReleaseCandidate
{
    public function __construct(private ReleaseRecord $record) {}
    public function record(): ReleaseRecord { return $this->record; }
    /** @return array<string, mixed> */
    public function toArray(): array { return $this->record->toArray() + ['kind' => 'candidate']; }
}
