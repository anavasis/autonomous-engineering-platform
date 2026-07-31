<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringExecution\Provider;

use Aep\Application\EngineeringExecution\Model\ProviderCapabilities;
use Aep\Application\EngineeringExecution\Model\ProviderEvent;
use Aep\Application\EngineeringExecution\Model\ProviderHealth;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Model\ProviderSessionRequest;
use Aep\Application\EngineeringExecution\Model\UsageMetrics;
use Aep\Application\EngineeringExecution\Port\EngineeringExecutionProvider;
use Aep\Application\MissionControl\Support\Utc;

/**
 * Shared session/event buffering for pluggable providers.
 */
abstract class AbstractBufferedProvider implements EngineeringExecutionProvider
{
    /** @var array<string, array{events: list<ProviderEvent>, result: ?ProviderResult, cancelled: bool, request: ?ProviderSessionRequest}> */
    private array $sessions = [];

    public function __construct(
        private readonly string $providerId,
        private readonly string $name,
        private readonly ProviderCapabilities $capabilities,
    ) {
    }

    public function id(): string
    {
        return $this->providerId;
    }

    public function displayName(): string
    {
        return $this->name;
    }

    public function capabilities(): ProviderCapabilities
    {
        return $this->capabilities;
    }

    abstract public function health(): ProviderHealth;

    public function start(ProviderSessionRequest $request): void
    {
        $this->sessions[$request->sessionId()] = [
            'events' => [],
            'result' => null,
            'cancelled' => false,
            'request' => $request,
        ];
        $this->push($request->sessionId(), 'provider.started', 'Provider session started');
        $this->run($request);
    }

    public function cancel(string $sessionId, string $reason = 'Cancelled.'): void
    {
        $this->ensure($sessionId);
        $this->sessions[$sessionId]['cancelled'] = true;
        $this->push($sessionId, 'provider.cancelled', $reason);
        $this->sessions[$sessionId]['result'] = new ProviderResult(ProviderResult::CANCELLED, $reason);
    }

    public function resume(string $sessionId, array $checkpoint): void
    {
        $this->ensure($sessionId);
        $request = $this->sessions[$sessionId]['request'];
        if ($request === null) {
            throw new \RuntimeException('Cannot resume: original request missing.');
        }
        $this->sessions[$sessionId]['cancelled'] = false;
        $this->sessions[$sessionId]['result'] = null;
        $this->push($sessionId, 'provider.resume', 'Resuming provider session', ['checkpoint' => $checkpoint]);
        $this->run($request);
    }

    public function poll(string $sessionId, int $afterSeq = 0): array
    {
        $this->ensure($sessionId);
        $out = [];
        foreach ($this->sessions[$sessionId]['events'] as $event) {
            if ($event->seq() > $afterSeq) {
                $out[] = $event;
            }
        }

        return $out;
    }

    public function collectResult(string $sessionId): ProviderResult
    {
        $this->ensure($sessionId);
        $result = $this->sessions[$sessionId]['result'];
        if ($result === null) {
            throw new \RuntimeException('Provider result not ready.');
        }

        return $result;
    }

    abstract protected function run(ProviderSessionRequest $request): void;

    protected function complete(string $sessionId, ProviderResult $result): void
    {
        $this->ensure($sessionId);
        if ($this->sessions[$sessionId]['cancelled']) {
            return;
        }
        $this->sessions[$sessionId]['result'] = $result;
        $type = match ($result->status()) {
            ProviderResult::SUCCEEDED => 'provider.completed',
            ProviderResult::REJECTED => 'provider.rejected',
            ProviderResult::CANCELLED => 'provider.cancelled',
            ProviderResult::TIMED_OUT => 'provider.timed_out',
            default => 'provider.failed',
        };
        $this->push($sessionId, $type, $result->message(), $result->toArray());
    }

    /** @param array<string, mixed> $data */
    protected function push(string $sessionId, string $type, string $message, array $data = []): void
    {
        $this->ensure($sessionId);
        $seq = count($this->sessions[$sessionId]['events']) + 1;
        $this->sessions[$sessionId]['events'][] = new ProviderEvent($seq, $type, $message, Utc::now(), $data);
    }

    protected function isCancelled(string $sessionId): bool
    {
        return ($this->sessions[$sessionId]['cancelled'] ?? false) === true;
    }

    protected function estimateUsage(ProviderSessionRequest $request, int $filesChanged = 0): UsageMetrics
    {
        $input = (int) ceil(strlen($request->prompt()->system() . $request->prompt()->user()) / 4);
        $output = 120 + ($filesChanged * 40);
        $usage = new UsageMetrics($input, $output, round(($input + $output) * 0.000002, 6), 0.01, 0, 0, $filesChanged);

        return $usage;
    }

    private function ensure(string $sessionId): void
    {
        if (!isset($this->sessions[$sessionId])) {
            throw new \InvalidArgumentException('Unknown provider session: ' . $sessionId);
        }
    }
}
