<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Service;

use Aep\Application\Execution\ExecutionRequest;

/**
 * Builds a bounded, redacted context pack for provider sessions.
 */
final class ContextPackager
{
    /**
     * @param list<string> $allowedPaths
     * @return array{notes: list<string>, files: array<string, string>, redactions: int}
     */
    public function pack(ExecutionRequest $request, array $allowedPaths, int $maxChars = 24000): array
    {
        $notes = [];
        $redactions = 0;
        foreach (['objective', 'summary', 'planSummary'] as $key) {
            $value = $request->contextValue($key);
            if (is_string($value) && $value !== '') {
                [$clean, $count] = $this->redact($value);
                $notes[] = $key . ': ' . $clean;
                $redactions += $count;
            }
        }

        $memory = $request->contextValue('engineeringKnowledge');
        if (is_array($memory)) {
            foreach ($memory as $item) {
                if (is_string($item) && $item !== '') {
                    [$clean, $count] = $this->redact($item);
                    $notes[] = $clean;
                    $redactions += $count;
                }
            }
        }

        $files = [];
        $budget = $maxChars;
        $provided = $request->contextValue('contextFiles');
        if (is_array($provided)) {
            foreach ($provided as $path => $contents) {
                if (!is_string($path) || !is_string($contents)) {
                    continue;
                }
                if (!$this->pathAllowed($path, $allowedPaths)) {
                    continue;
                }
                [$clean, $count] = $this->redact($contents);
                $redactions += $count;
                if (strlen($clean) > $budget) {
                    $clean = substr($clean, 0, $budget) . "\n…[truncated]";
                }
                $budget -= strlen($clean);
                $files[$path] = $clean;
                if ($budget <= 0) {
                    break;
                }
            }
        }

        return ['notes' => $notes, 'files' => $files, 'redactions' => $redactions];
    }

    /**
     * @param list<string> $allowedPaths
     */
    private function pathAllowed(string $path, array $allowedPaths): bool
    {
        foreach ($allowedPaths as $allowed) {
            if ($allowed !== '' && (str_starts_with($path, $allowed) || $path === rtrim($allowed, '/'))) {
                return true;
            }
        }

        return $allowedPaths === [];
    }

    /** @return array{0: string, 1: int} */
    private function redact(string $text): array
    {
        $patterns = [
            '/(?i)(api[_-]?key|token|password|secret)\s*[:=]\s*\S+/' => '$1=[REDACTED]',
            '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/' => 'Bearer [REDACTED]',
        ];
        $count = 0;
        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text, -1, $c) ?? $text;
            $count += is_int($c) ? $c : 0;
        }

        return [$text, $count];
    }
}
