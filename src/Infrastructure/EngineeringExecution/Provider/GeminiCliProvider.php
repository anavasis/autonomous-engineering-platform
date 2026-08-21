<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringExecution\Provider;

use Aep\Application\EngineeringExecution\Model\ProviderCapabilities;

/**
 * Thin Gemini CLI provider — Gemini identity and defaults only.
 * Execution pipeline lives in ExternalCliProvider.
 *
 * Headless invocation defaults to: gemini -p "<prompt>"
 * (promptViaStdin=false; additional args configurable via execution-providers.json).
 */
final class GeminiCliProvider extends ExternalCliProvider
{
    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        if (!isset($options['id']) || !is_string($options['id']) || trim($options['id']) === '') {
            $options['id'] = 'gemini-cli';
        }
        if (!isset($options['displayName']) || !is_string($options['displayName']) || trim($options['displayName']) === '') {
            $options['displayName'] = 'Gemini CLI';
        }
        if (!isset($options['envBinaryKey']) || !is_string($options['envBinaryKey']) || trim($options['envBinaryKey']) === '') {
            $options['envBinaryKey'] = 'AEP_GEMINI_CLI_BINARY';
        }
        if (!isset($options['defaultBinary']) || !is_string($options['defaultBinary']) || trim($options['defaultBinary']) === '') {
            $options['defaultBinary'] = 'gemini';
        }
        if (!isset($options['label']) || !is_string($options['label']) || trim($options['label']) === '') {
            $options['label'] = 'Gemini CLI';
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
                maxContextTokens: 1000000,
                supportsImages: false,
                costReporting: true,
                parallelSessions: false,
            ))->toArray();
        }

        parent::__construct($options);
    }
}
