<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Service;

use Aep\Application\EngineeringExecution\Model\PromptBundle;

final class WorkspacePreparer
{
    private readonly string $executionRoot;

    public function __construct(string $executionRoot)
    {
        $this->executionRoot = rtrim($executionRoot, "/\\");
    }

    /**
     * @param array<string, string> $contextFiles
     */
    public function prepare(string $sessionId, PromptBundle $prompt, array $contextFiles): string
    {
        $dir = $this->executionRoot . '/workspaces/' . $this->safe($sessionId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create execution workspace: ' . $dir);
        }
        file_put_contents($dir . '/PROMPT.md', "# System\n\n" . $prompt->system() . "\n\n# User\n\n" . $prompt->user() . "\n");
        file_put_contents($dir . '/prompt.hash', $prompt->hash() . "\n");
        $ctxDir = $dir . '/context';
        if (!is_dir($ctxDir)) {
            mkdir($ctxDir, 0775, true);
        }
        $baselineDir = $dir . '/.baseline';
        if (!is_dir($baselineDir)) {
            mkdir($baselineDir, 0775, true);
        }
        foreach ($contextFiles as $path => $contents) {
            $safeRel = str_replace(['..', '\\'], ['_', '/'], $path);
            $target = $ctxDir . '/' . $safeRel;
            $parent = dirname($target);
            if (!is_dir($parent)) {
                mkdir($parent, 0775, true);
            }
            file_put_contents($target, $contents);
            $baseTarget = $baselineDir . '/' . $safeRel;
            $baseParent = dirname($baseTarget);
            if (!is_dir($baseParent)) {
                mkdir($baseParent, 0775, true);
            }
            file_put_contents($baseTarget, $contents);
        }
        file_put_contents($dir . '/workspace.json', json_encode([
            'sessionId' => $sessionId,
            'promptHash' => $prompt->hash(),
            'fileCount' => count($contextFiles),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $dir;
    }

    private function safe(string $id): string
    {
        if ($id === '' || preg_match('/[^A-Za-z0-9_-]/', $id) === 1) {
            throw new \InvalidArgumentException('Unsafe session id.');
        }

        return $id;
    }
}
