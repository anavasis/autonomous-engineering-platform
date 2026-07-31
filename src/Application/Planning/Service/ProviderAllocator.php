<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Service;

use Aep\Application\Planning\Model\ProgramNode;

final class ProviderAllocator
{
    /**
     * @param array<string, mixed> $executionSettings
     */
    public function allocate(ProgramNode $node, array $executionSettings): ProgramNode
    {
        if ($node->providerId() !== null && $node->providerId() !== '') {
            return $node;
        }
        $default = is_string($executionSettings['defaultProviderId'] ?? null)
            ? $executionSettings['defaultProviderId']
            : 'local-agent';
        $enabled = is_array($executionSettings['enabledProviderIds'] ?? null)
            ? $executionSettings['enabledProviderIds']
            : [];
        if ($enabled !== [] && !in_array($default, $enabled, true)) {
            $first = null;
            foreach ($enabled as $id) {
                if (is_string($id)) {
                    $first = $id;
                    break;
                }
            }
            $default = $first ?? $default;
        }

        return $node->withProvider($default);
    }
}
