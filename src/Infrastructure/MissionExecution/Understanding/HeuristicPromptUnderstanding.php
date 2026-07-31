<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionExecution\Understanding;

use Aep\Application\MissionExecution\Model\MissionIntent;
use Aep\Application\MissionExecution\Model\ProjectMemory;
use Aep\Application\MissionExecution\Port\PromptUnderstandingPort;

/**
 * Deterministic NL → MissionIntent interpreter (no LLM).
 */
final class HeuristicPromptUnderstanding implements PromptUnderstandingPort
{
    public function understand(
        string $text,
        ?string $projectIdHint,
        array $projectSummaries,
        ?ProjectMemory $memory,
    ): MissionIntent {
        $text = trim($text);
        $lower = strtolower($text);

        $changeType = 'fix';
        if (preg_match('/\b(add|implement|feature|build)\b/', $lower) === 1) {
            $changeType = 'feature';
        } elseif (preg_match('/\b(refactor|cleanup|restructure)\b/', $lower) === 1) {
            $changeType = 'refactor';
        } elseif (preg_match('/\b(inspect|investigate|audit|review only)\b/', $lower) === 1) {
            $changeType = 'inspect';
        } elseif (preg_match('/\b(fix|bug|broken|issue|error)\b/', $lower) === 1) {
            $changeType = 'fix';
        }

        $inspectionFirst = true;
        if (preg_match('/\binspection first\b/', $lower) === 1) {
            $inspectionFirst = true;
        }
        if (preg_match('/\b(skip inspection|no inspection)\b/', $lower) === 1) {
            $inspectionFirst = false;
        }

        $nonGoals = [];
        if (preg_match_all('/do not (?:modify|change|touch|alter)\s+([^.!\n]+)/i', $text, $matches) > 0) {
            foreach ($matches[1] as $item) {
                $nonGoals[] = 'Do not modify ' . trim($item, " \t\"'");
            }
        }

        $affected = [];
        if (preg_match('/\bclient panel\b/i', $text) === 1) {
            $affected[] = 'Client Panel';
        }
        if (preg_match('/\bemail formatting\b/i', $text) === 1) {
            $affected[] = 'email formatting';
        }
        if (preg_match_all('/\b([A-Z][A-Za-z0-9]+(?:\s+[A-Z][A-Za-z0-9]+)+)\b/', $text, $m) > 0) {
            foreach ($m[1] as $phrase) {
                if (!in_array($phrase, $affected, true) && !preg_match('/\b(Fix|Do|Inspection)\b/', $phrase)) {
                    $affected[] = $phrase;
                }
            }
        }

        $allowedPaths = [];
        if ($memory !== null) {
            foreach ($affected as $area) {
                $resolved = $memory->resolveAlias($area);
                if ($resolved !== null) {
                    $allowedPaths[] = $resolved;
                }
            }
        }
        if (preg_match_all('#\b(?:src|app|apps|wp-content|includes)/[\w./-]+#', $text, $pm) > 0) {
            foreach ($pm[0] as $path) {
                $allowedPaths[] = $path;
            }
        }
        $allowedPaths = array_values(array_unique($allowedPaths));

        $tests = [];
        if (preg_match('/\b(unit tests?|add tests?|e2e|phpunit)\b/i', $text) === 1) {
            $tests[] = 'Ensure automated tests cover the change';
        }
        if (preg_match('/\bemail\b/i', $text) === 1) {
            $tests[] = 'Verify email-related formatting behavior';
        }

        $projectId = $projectIdHint;
        $projectSlug = null;
        if ($projectId === null) {
            foreach ($projectSummaries as $p) {
                $slug = strtolower((string) ($p['slug'] ?? ''));
                $name = strtolower((string) ($p['displayName'] ?? ''));
                if ($slug !== '' && str_contains($lower, $slug)) {
                    $projectId = (string) $p['id'];
                    $projectSlug = (string) ($p['slug'] ?? null);
                    break;
                }
                if ($name !== '' && str_contains($lower, $name)) {
                    $projectId = (string) $p['id'];
                    $projectSlug = is_string($p['slug'] ?? null) ? $p['slug'] : null;
                    break;
                }
            }
        }

        $provider = null;
        $repository = null;
        if (preg_match('#\b(github|gitlab|bitbucket):([\w.-]+/[\w.-]+)#i', $text, $rm) === 1) {
            $provider = strtolower($rm[1]);
            $repository = $rm[2];
        }

        $objective = preg_replace('/\s+/', ' ', $text) ?? $text;
        $objective = trim($objective, " \t\"'");

        $confidence = [
            'changeType' => 0.8,
            'objective' => 0.9,
            'project' => $projectId !== null ? 0.85 : 0.3,
            'paths' => $allowedPaths !== [] ? 0.75 : 0.4,
        ];

        return new MissionIntent(
            $objective,
            $changeType,
            $projectId,
            $projectSlug,
            $provider,
            $repository,
            $affected,
            $allowedPaths,
            $nonGoals,
            [],
            $tests,
            'standard',
            $inspectionFirst,
            [],
            $confidence,
            $text
        );
    }
}
