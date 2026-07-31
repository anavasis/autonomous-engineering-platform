<?php

declare(strict_types=1);

namespace Aep\Application\Artifact;

/**
 * Optional capability for stores that can archive a sealed workspace.
 */
interface ArchiveCapableArtifactStore extends ArtifactStore
{
    public function archive(Workspace $workspace, string $occurredAtUtc): Artifact;
}
