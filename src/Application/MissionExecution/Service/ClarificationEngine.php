<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Service;

use Aep\Application\MissionExecution\Model\ClarificationQuestion;
use Aep\Application\MissionExecution\Model\MissionIntent;
use Aep\Application\MissionExecution\Model\ProjectMemory;

/**
 * Adaptive clarification — ask the minimum possible questions.
 */
final class ClarificationEngine
{
    /**
     * @param list<array<string, mixed>> $projects
     * @return list<ClarificationQuestion>
     */
    public function questions(
        MissionIntent $intent,
        array $projects,
        ?ProjectMemory $memory,
    ): array
    {
        $questions = [];

        $projectId = $intent->projectId();
        if ($projectId === null || $projectId === '') {
            if (count($projects) === 0) {
                // No project registry — do not ask; validator will reject or use unbound target.
            } elseif (count($projects) === 1) {
                // Single project — no question; caller should auto-fill.
            } else {
                $choices = [];
                foreach ($projects as $p) {
                    $id = (string) ($p['id'] ?? '');
                    $label = (string) ($p['displayName'] ?? $p['slug'] ?? $id);
                    if ($id !== '') {
                        $choices[] = ['value' => $id, 'label' => $label];
                    }
                }
                $questions[] = new ClarificationQuestion(
                    'q_project',
                    'projectId',
                    'Which project should this mission target?',
                    $choices
                );
            }
        }

        $needsTarget = ($intent->provider() === null || $intent->provider() === '')
            || ($intent->repository() === null || $intent->repository() === '');
        if ($needsTarget) {
            $bound = false;
            foreach ($projects as $p) {
                $matchesProject = $projectId === null || ($p['id'] ?? null) === $projectId;
                if ($matchesProject && ($p['repositoryStatus'] ?? '') === 'bound') {
                    $bound = true;
                    break;
                }
            }
            if (!$bound) {
                $questions[] = new ClarificationQuestion(
                    'q_repository',
                    'repository',
                    'Provide target repository as provider:repo (e.g. github:org/repo).',
                );
            }
        }

        $pathsKnown = $intent->allowedPaths() !== [];
        if (!$pathsKnown && $memory !== null && $memory->defaultAllowedPaths() !== []) {
            $pathsKnown = true;
        }
        if (!$pathsKnown && $intent->affectedAreas() !== []) {
            $resolved = false;
            if ($memory !== null) {
                foreach ($intent->affectedAreas() as $area) {
                    if ($memory->resolveAlias($area) !== null) {
                        $resolved = true;
                        break;
                    }
                }
            }
            $pathsKnown = $resolved;
        }
        if (!$pathsKnown && $this->looksLikePathHint($intent)) {
            $pathsKnown = true;
        }
        if (!$pathsKnown) {
            $questions[] = new ClarificationQuestion(
                'q_paths',
                'allowedPaths',
                'Which paths may be modified? (comma-separated, e.g. src/ClientPanel/)',
            );
        }

        if (trim($intent->objective()) === '') {
            $questions[] = new ClarificationQuestion(
                'q_objective',
                'objective',
                'Please restate the mission objective in one sentence.',
            );
        }

        return $questions;
    }

    /**
     * Auto-fill intent slots from context without asking.
     *
     * @param list<array<string, mixed>> $projects
     */
    public function enrich(MissionIntent $intent, array $projects, ?ProjectMemory $memory): MissionIntent
    {
        $enriched = $intent;

        if (($enriched->projectId() === null || $enriched->projectId() === '') && count($projects) === 1) {
            $enriched = $enriched->withProjectId((string) $projects[0]['id']);
        }

        $projectId = $enriched->projectId();
        if ($projectId !== null) {
            foreach ($projects as $p) {
                if (($p['id'] ?? null) !== $projectId) {
                    continue;
                }
                $repo = $p['repository'] ?? null;
                if (is_array($repo) && ($enriched->provider() === null || $enriched->repository() === null)) {
                    $enriched = $enriched->withTarget(
                        is_string($repo['provider'] ?? null) ? $repo['provider'] : 'github',
                        is_string($repo['repository'] ?? null) ? $repo['repository'] : null
                    );
                }
            }
        }

        $paths = $enriched->allowedPaths();
        if ($paths === [] && $memory !== null) {
            foreach ($enriched->affectedAreas() as $area) {
                $resolved = $memory->resolveAlias($area);
                if ($resolved !== null) {
                    $paths[] = $resolved;
                }
            }
            if ($paths === []) {
                $paths = $memory->defaultAllowedPaths();
            }
            if ($paths !== []) {
                $enriched = $enriched->withAllowedPaths($paths);
            }
        }
        if ($enriched->allowedPaths() === [] && $paths === []) {
            $hinted = $this->extractPathHints($enriched);
            if ($hinted !== []) {
                $enriched = $enriched->withAllowedPaths($hinted);
            }
        }

        $nonGoals = $enriched->nonGoals();
        if ($memory !== null) {
            foreach ($memory->defaultNonGoals() as $ng) {
                if (!in_array($ng, $nonGoals, true)) {
                    $nonGoals[] = $ng;
                }
            }
            foreach ($memory->reusableConstraints() as $c) {
                if (str_starts_with(strtolower($c), 'do not ') && !in_array($c, $nonGoals, true)) {
                    $nonGoals[] = $c;
                }
            }
            if ($nonGoals !== $enriched->nonGoals()) {
                $enriched = $enriched->withNonGoals($nonGoals);
            }
        }

        return $enriched;
    }

    private function looksLikePathHint(MissionIntent $intent): bool
    {
        return $this->extractPathHints($intent) !== [];
    }

    /** @return list<string> */
    private function extractPathHints(MissionIntent $intent): array
    {
        $text = $intent->rawText() . ' ' . implode(' ', $intent->affectedAreas());
        if (preg_match_all('#\b[\w./-]+/(?:[\w./-]+)#', $text, $m) !== false) {
            $paths = [];
            foreach ($m[0] as $path) {
                if (str_contains($path, '/') && !str_contains($path, '://')) {
                    $paths[] = $path;
                }
            }

            return array_values(array_unique($paths));
        }

        return [];
    }
}
