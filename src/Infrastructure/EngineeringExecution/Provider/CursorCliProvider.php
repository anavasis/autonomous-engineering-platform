<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringExecution\Provider;

use Aep\Application\EngineeringExecution\Model\ProviderCapabilities;

/**
 * Thin Cursor CLI provider — Cursor identity and defaults only.
 * Execution pipeline lives in ExternalCliProvider.
 */
final class CursorCliProvider extends ExternalCliProvider
{
    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        if (!isset($options['id']) || !is_string($options['id']) || trim($options['id']) === '') {
            $options['id'] = 'cursor';
        }
        if (!isset($options['displayName']) || !is_string($options['displayName']) || trim($options['displayName']) === '') {
            $options['displayName'] = 'Cursor Agent';
        }
        if (!isset($options['envBinaryKey']) || !is_string($options['envBinaryKey']) || trim($options['envBinaryKey']) === '') {
            $options['envBinaryKey'] = 'AEP_CURSOR_CLI_BINARY';
        }
        if (!isset($options['defaultBinary']) || !is_string($options['defaultBinary']) || trim($options['defaultBinary']) === '') {
            $options['defaultBinary'] = 'cursor-agent';
        }
        if (!isset($options['label']) || !is_string($options['label']) || trim($options['label']) === '') {
            $options['label'] = 'Cursor CLI';
        }
        if (!array_key_exists('useRepoCwd', $options)) {
            $options['useRepoCwd'] = true;
        }
        if (!array_key_exists('promptViaStdin', $options)) {
            $options['promptViaStdin'] = true;
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
