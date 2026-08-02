<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringExecution\Provider;

use Aep\Application\EngineeringExecution\Model\ProviderCapabilities;

/**
 * Thin Claude Code CLI provider — Claude identity and defaults only.
 * Execution pipeline lives in ExternalCliProvider.
 *
 * Headless invocation defaults to: claude -p "<prompt>"
 * (promptViaStdin=false; additional args configurable via execution-providers.json).
 */
final class ClaudeCodeCliProvider extends ExternalCliProvider
{
    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        if (!isset($options['id']) || !is_string($options['id']) || trim($options['id']) === '') {
            $options['id'] = 'claude-code';
        }
        if (!isset($options['displayName']) || !is_string($options['displayName']) || trim($options['displayName']) === '') {
            $options['displayName'] = 'Claude Code';
        }
        if (!isset($options['envBinaryKey']) || !is_string($options['envBinaryKey']) || trim($options['envBinaryKey']) === '') {
            $options['envBinaryKey'] = 'AEP_CLAUDE_CODE_CLI_BINARY';
        }
        if (!isset($options['defaultBinary']) || !is_string($options['defaultBinary']) || trim($options['defaultBinary']) === '') {
            $options['defaultBinary'] = 'claude';
        }
        if (!isset($options['label']) || !is_string($options['label']) || trim($options['label']) === '') {
            $options['label'] = 'Claude Code CLI';
        }
        if (!array_key_exists('useRepoCwd', $options)) {
            $options['useRepoCwd'] = true;
        }
        if (!array_key_exists('promptViaStdin', $options)) {
            $options['promptViaStdin'] = false;
        }
        if (!isset($options['args']) || !is_array($options['args'])) {
            $options['args'] = ['-p'];
        }
        if (!isset($options['capabilities']) || !is_array($options['capabilities'])) {
            $options['capabilities'] = (new ProviderCapabilities(
                streaming: true,
                cancel: true,
                resume: true,
                workspaceMount: true,
                diffExport: true,
                tools: true,
                maxContextTokens: 200000,
                supportsImages: false,
                costReporting: true,
                parallelSessions: false,
            ))->toArray();
        }

        parent::__construct($options);
    }
}
