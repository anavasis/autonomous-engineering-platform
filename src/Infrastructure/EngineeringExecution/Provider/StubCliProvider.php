<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringExecution\Provider;

use Aep\Application\EngineeringExecution\Model\ProviderCapabilities;
use Aep\Application\EngineeringExecution\Model\ProviderHealth;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Model\ProviderSessionRequest;
use Aep\Application\MissionControl\Support\Utc;

/**
 * Configuration-driven stub for external CLI/API providers (Cursor, Codex, Claude, Gemini).
 * Reports capability/health from options; executes a deterministic offline simulation unless
 * a binary path is configured and present.
 */
final class StubCliProvider extends AbstractBufferedProvider
{
    private readonly string $binary;
    private readonly bool $simulate;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $id = is_string($options['id'] ?? null) ? (string) $options['id'] : 'stub';
        $name = is_string($options['displayName'] ?? null) ? (string) $options['displayName'] : $id;
        $this->binary = is_string($options['binary'] ?? null) ? (string) $options['binary'] : '';
        $this->simulate = ($options['simulate'] ?? true) === true;
        $caps = isset($options['capabilities']) && is_array($options['capabilities'])
            ? ProviderCapabilities::fromArray($options['capabilities'])
            : new ProviderCapabilities(
                streaming: true,
                cancel: true,
                resume: false,
                workspaceMount: true,
                diffExport: true,
                tools: true,
                maxContextTokens: is_int($options['maxContextTokens'] ?? null) ? (int) $options['maxContextTokens'] : 128000,
                supportsImages: false,
                costReporting: true,
                parallelSessions: false,
            );
        parent::__construct($id, $name, $caps);
    }

    public function health(): ProviderHealth
    {
        if ($this->binary !== '' && !is_file($this->binary) && !$this->simulate) {
            return new ProviderHealth('unavailable', 'Binary not found: ' . $this->binary, Utc::now());
        }
        if ($this->simulate) {
            return new ProviderHealth('degraded', $this->displayName() . ' running in simulate mode', Utc::now());
        }

        return new ProviderHealth('ok', $this->displayName() . ' ready', Utc::now());
    }

    protected function run(ProviderSessionRequest $request): void
    {
        if ($this->isCancelled($request->sessionId())) {
            return;
        }
        $health = $this->health();
        if (!$health->isAvailable()) {
            $this->complete(
                $request->sessionId(),
                new ProviderResult(ProviderResult::REJECTED, $health->message())
            );

            return;
        }

        $this->push($request->sessionId(), 'log', $this->displayName() . ' accepted prompt ' . $request->prompt()->hash());
        $workspace = rtrim($request->workspacePath(), '/');
        $rel = 'src/' . preg_replace('/[^a-z0-9]+/', '_', strtolower($this->id())) . '_change.txt';
        $path = $workspace . '/context/' . $rel;
        $parent = dirname($path);
        if (!is_dir($parent)) {
            mkdir($parent, 0775, true);
        }
        $body = $this->id() . ':' . $request->action() . ':' . Utc::now() . "\n";
        file_put_contents($path, $body);
        $diff = "--- a/{$rel}\n+++ b/{$rel}\n@@\n+{$body}";
        file_put_contents($workspace . '/RESULT.diff', $diff);
        $usage = $this->estimateUsage($request, 1);
        $this->complete(
            $request->sessionId(),
            new ProviderResult(
                ProviderResult::SUCCEEDED,
                $this->displayName() . ' completed ' . $request->action(),
                [$rel],
                $diff,
                $usage,
                ['mode' => $this->simulate ? 'simulate' : 'binary', 'binary' => $this->binary]
            )
        );
    }
}
