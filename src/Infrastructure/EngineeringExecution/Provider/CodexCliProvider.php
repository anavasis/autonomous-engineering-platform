<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringExecution\Provider;

use Aep\Application\EngineeringExecution\Model\ProviderCapabilities;

/**
 * Thin OpenAI Codex CLI provider — Codex identity and defaults only.
 * Execution pipeline lives in ExternalCliProvider.
 *
 * Headless invocation defaults to:
 *   codex exec --sandbox workspace-write "<prompt>"
 * (promptViaStdin=false; additional args configurable via execution-providers.json).
 */
final class CodexCliProvider extends ExternalCliProvider
{
    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        if (!isset($options['id']) || !is_string($options['id']) || trim($options['id']) === '') {
            $options['id'] = 'codex';
        }
        if (!isset($options['displayName']) || !is_string($options['displayName']) || trim($options['displayName']) === '') {
            $options['displayName'] = 'OpenAI Codex';
        }
        if (!isset($options['envBinaryKey']) || !is_string($options['envBinaryKey']) || trim($options['envBinaryKey']) === '') {
            $options['envBinaryKey'] = 'AEP_CODEX_CLI_BINARY';
        }
        if (!isset($options['defaultBinary']) || !is_string($options['defaultBinary']) || trim($options['defaultBinary']) === '') {
            $options['defaultBinary'] = 'codex';
        }
        if (!isset($options['label']) || !is_string($options['label']) || trim($options['label']) === '') {
            $options['label'] = 'Codex CLI';
        }
        if (!array_key_exists('useRepoCwd', $options)) {
            $options['useRepoCwd'] = true;
        }
        if (!array_key_exists('promptViaStdin', $options)) {
            $options['promptViaStdin'] = false;
        }
        if (!isset($options['args']) || !is_array($options['args'])) {
            $options['args'] = ['exec', '--sandbox', 'workspace-write'];
        }
        if (!isset($options['capabilities']) || !is_array($options['capabilities'])) {
            $options['capabilities'] = (new ProviderCapabilities(
                streaming: true,
                cancel: true,
                resume: true,
                workspaceMount: true,
                diffExport: true,
                tools: true,
                maxContextTokens: 128000,
                supportsImages: false,
                costReporting: true,
                parallelSessions: false,
            ))->toArray();
        }

        parent::__construct($options);
    }
}
