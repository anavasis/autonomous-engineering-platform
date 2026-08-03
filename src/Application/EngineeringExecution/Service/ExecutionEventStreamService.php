<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Service;

use Aep\Application\EngineeringExecution\Model\ExecutionSession;
use Aep\Application\EngineeringExecution\Model\ProviderEvent;
use Aep\Application\EngineeringExecution\Port\ExecutionSessionStore;

/**
 * Store-backed execution event streaming (SSE).
 *
 * Viewers independently tail JsonExecutionSessionStore — no shared subscriber state.
 * Orchestrator persists events; this service formats/publishes and serves the HTTP stream.
 */
final class ExecutionEventStreamService
{
    /** @var list<string> */
    private const TERMINAL_EVENT_TYPES = [
        'completed',
        'failed',
        'cancelled',
        'rejected',
        'timed_out',
        'provider.completed',
        'provider.failed',
        'provider.cancelled',
        'provider.rejected',
        'provider.timed_out',
    ];

    /** @var list<string> */
    private const TERMINAL_SESSION_STATUSES = [
        ExecutionSession::STATUS_SUCCEEDED,
        ExecutionSession::STATUS_FAILED,
        ExecutionSession::STATUS_REJECTED,
        ExecutionSession::STATUS_CANCELLED,
        ExecutionSession::STATUS_TIMED_OUT,
    ];

    public function __construct(
        private readonly ExecutionSessionStore $sessions,
        private readonly int $pollIntervalMs = 200,
        private readonly int $heartbeatSeconds = 15,
        private readonly int $maxDurationSeconds = 3600,
    ) {
    }

    /**
     * Hook for orchestrator forwarding. Store is the source of truth for multi-viewer SSE;
     * no in-process subscriber registry is kept (viewers reconnect via afterSeq).
     */
    public function publish(string $sessionId, ProviderEvent $event): void
    {
        unset($sessionId, $event);
    }

    /**
     * @param callable(string $chunk): void $write
     * @param callable(): bool|null $shouldStop
     */
    public function stream(
        string $sessionId,
        int $afterSeq,
        callable $write,
        ?callable $shouldStop = null,
        ?int $maxDurationSeconds = null,
    ): void {
        $afterSeq = max(0, $afterSeq);
        $deadline = time() + max(1, $maxDurationSeconds ?? $this->maxDurationSeconds);
        $lastHeartbeat = time();
        $idleRounds = 0;

        while (true) {
            if ($shouldStop !== null && $shouldStop()) {
                $write($this->formatDone($afterSeq, 'stopped'));

                return;
            }
            if (time() >= $deadline) {
                $write($this->formatDone($afterSeq, 'timeout'));

                return;
            }

            $batch = $this->sessions->events($sessionId, $afterSeq);
            $sawTerminalEvent = false;
            foreach ($batch as $event) {
                $afterSeq = max($afterSeq, $event->seq());
                $write($this->formatEvent($event));
                if (in_array($event->type(), self::TERMINAL_EVENT_TYPES, true)) {
                    $sawTerminalEvent = true;
                }
            }

            if ($sawTerminalEvent) {
                $write($this->formatDone($afterSeq, 'terminal_event'));

                return;
            }

            $session = $this->sessions->find($sessionId);
            if ($session !== null && in_array($session->status(), self::TERMINAL_SESSION_STATUSES, true)) {
                // Flush any final events, then close.
                $tail = $this->sessions->events($sessionId, $afterSeq);
                foreach ($tail as $event) {
                    $afterSeq = max($afterSeq, $event->seq());
                    $write($this->formatEvent($event));
                }
                $write($this->formatDone($afterSeq, 'session_' . $session->status()));

                return;
            }

            if ($batch === []) {
                $idleRounds++;
            } else {
                $idleRounds = 0;
                $lastHeartbeat = time();
            }

            if (time() - $lastHeartbeat >= $this->heartbeatSeconds) {
                $write(": heartbeat\n\n");
                $lastHeartbeat = time();
            }

            // Safety for tests / idle empty sessions that never start.
            if ($session === null && $idleRounds > 5) {
                $write($this->formatDone($afterSeq, 'missing_session'));

                return;
            }

            usleep(max(1, $this->pollIntervalMs) * 1000);
        }
    }

    /**
     * Emit text/event-stream over the current HTTP response.
     */
    public function streamHttp(string $sessionId, int $afterSeq): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-transform');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            header('X-Content-Type-Options: nosniff');
        }
        // In web SAPIs, drop output buffering so frames flush immediately.
        // Keep buffers under CLI (verification harness captures via ob_start).
        if (PHP_SAPI !== 'cli') {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
        }

        $this->stream(
            $sessionId,
            $afterSeq,
            static function (string $chunk): void {
                echo $chunk;
                if (PHP_SAPI !== 'cli') {
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();
                }
            }
        );
    }

    public function formatEvent(ProviderEvent $event): string
    {
        $data = json_encode($event->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return 'id: ' . $event->seq() . "\n"
            . "event: execution\n"
            . 'data: ' . $data . "\n\n";
    }

    public function formatDone(int $lastSeq, string $reason): string
    {
        $data = json_encode([
            'seq' => $lastSeq,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return "event: done\n"
            . 'data: ' . $data . "\n\n";
    }
}
