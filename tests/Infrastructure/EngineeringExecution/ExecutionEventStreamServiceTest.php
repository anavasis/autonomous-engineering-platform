<?php

declare(strict_types=1);

namespace Tests\Infrastructure\EngineeringExecution;

use Aep\Application\EngineeringExecution\Model\ExecutionSession;
use Aep\Application\EngineeringExecution\Model\ProviderCapabilities;
use Aep\Application\EngineeringExecution\Model\ProviderEvent;
use Aep\Application\EngineeringExecution\Model\ProviderHealth;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Model\ProviderSessionRequest;
use Aep\Application\EngineeringExecution\Service\ArtifactCapture;
use Aep\Application\EngineeringExecution\Service\ContextPackager;
use Aep\Application\EngineeringExecution\Service\DiffCollector;
use Aep\Application\EngineeringExecution\Service\EngineeringExecutionOrchestrator;
use Aep\Application\EngineeringExecution\Service\ExecutionEventStreamService;
use Aep\Application\EngineeringExecution\Service\PromptPipeline;
use Aep\Application\EngineeringExecution\Service\ResultNormalizer;
use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Infrastructure\EngineeringExecution\Provider\AbstractBufferedProvider;
use Aep\Infrastructure\EngineeringExecution\Registry\ConfigProviderRegistry;
use Aep\Infrastructure\EngineeringExecution\Store\JsonExecutionSessionStore;
use Tests\Support\Assert;
use Tests\Support\EngineeringWorkspaceTestFactory;

final class ExecutionEventStreamServiceTest
{
    public function test_stream_emits_events_after_seq_and_done_on_terminal_session(): void
    {
        $root = sys_get_temp_dir() . '/aep_sse_' . bin2hex(random_bytes(4));
        $store = new JsonExecutionSessionStore($root . '/execution');
        $session = new ExecutionSession(
            'esess_sse_1',
            'msn_sse_1',
            'run_sse_1',
            'local-agent',
            ExecutionSession::STATUS_SUCCEEDED,
            Utc::now(),
            Utc::now(),
            'done',
        );
        $store->save($session);
        $store->appendEvent('esess_sse_1', new ProviderEvent(1, 'log', 'first', Utc::now()));
        $store->appendEvent('esess_sse_1', new ProviderEvent(2, 'log', 'second', Utc::now()));
        $store->appendEvent('esess_sse_1', new ProviderEvent(3, 'provider.completed', 'ok', Utc::now()));

        $stream = new ExecutionEventStreamService($store, 10, 60, 5);
        $chunks = [];
        $stream->stream('esess_sse_1', 1, static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }, null, 2);

        $body = implode('', $chunks);
        Assert::true(str_contains($body, 'event: execution'));
        Assert::true(str_contains($body, '"seq":2'));
        Assert::true(str_contains($body, '"seq":3'));
        Assert::true(!str_contains($body, '"seq":1'));
        Assert::true(str_contains($body, 'event: done'));
        $this->removeDir($root);
    }

    public function test_orchestrator_persists_provider_events_during_run(): void
    {
        $root = sys_get_temp_dir() . '/aep_sse_live_' . bin2hex(random_bytes(4));
        $executionDir = $root . '/execution';
        mkdir($executionDir, 0775, true);
        $store = new JsonExecutionSessionStore($executionDir);
        $mid = new \stdClass();
        $mid->count = 0;

        $registry = new ConfigProviderRegistry([
            'providers' => [
                ['id' => 'probe', 'type' => 'probe', 'enabled' => true],
            ],
        ], [
            'probe' => static function () use ($store, $mid): AbstractBufferedProvider {
                return new class ($store, $mid) extends AbstractBufferedProvider {
                    public function __construct(
                        private JsonExecutionSessionStore $eventStore,
                        private \stdClass $mid,
                    ) {
                        parent::__construct(
                            'probe',
                            'Probe',
                            new ProviderCapabilities(
                                streaming: true,
                                cancel: true,
                                resume: false,
                                workspaceMount: true,
                                diffExport: true,
                                tools: false,
                                maxContextTokens: 1000,
                            )
                        );
                    }

                    public function health(): ProviderHealth
                    {
                        return new ProviderHealth('ok', 'ready', Utc::now());
                    }

                    protected function run(ProviderSessionRequest $request): void
                    {
                        $this->push($request->sessionId(), 'log', 'live-one', ['stream' => 'stdout']);
                        $this->mid->count = count($this->eventStore->events($request->sessionId()));
                        $this->push($request->sessionId(), 'log', 'live-two', ['stream' => 'stdout']);
                        $this->complete(
                            $request->sessionId(),
                            new ProviderResult(ProviderResult::SUCCEEDED, 'probe ok')
                        );
                    }
                };
            },
        ]);

        $factory = EngineeringWorkspaceTestFactory::make($root);
        $stream = new ExecutionEventStreamService($store);
        $orchestrator = new EngineeringExecutionOrchestrator(
            $registry,
            $store,
            new PromptPipeline(),
            new ContextPackager(),
            $factory['preparer'],
            new DiffCollector(),
            new ArtifactCapture($factory['artifacts']),
            new ResultNormalizer(),
            'provider_routing',
            0,
            20,
            50,
            $stream,
        );

        $result = $orchestrator->execute(new ExecutionRequest(
            'msn_live_1',
            'implement',
            '2026-07-31T12:00:00Z',
            ['providerId' => 'probe', 'runId' => 'run_live_1', 'objective' => 'probe live']
        ));

        Assert::true($result->isSucceeded());
        Assert::true($mid->count >= 2, 'expected store events during provider run, got ' . $mid->count);
        $sessionId = (string) ($result->context()['sessionId'] ?? '');
        $messages = array_map(
            static fn (ProviderEvent $e): string => $e->message(),
            $store->events($sessionId)
        );
        Assert::true(in_array('live-one', $messages, true));
        Assert::true(in_array('live-two', $messages, true));
        Assert::same(1, count(array_filter($messages, static fn (string $m): bool => $m === 'live-one')));
        $this->removeDir($root);
    }

    public function test_format_event_reuses_provider_event_payload(): void
    {
        $root = sys_get_temp_dir() . '/aep_sse_fmt_' . bin2hex(random_bytes(4));
        $store = new JsonExecutionSessionStore($root . '/execution');
        $stream = new ExecutionEventStreamService($store);
        $event = new ProviderEvent(7, 'log', 'hello', '2026-08-02T00:00:00Z', ['stream' => 'stderr']);
        $frame = $stream->formatEvent($event);
        Assert::true(str_starts_with($frame, "id: 7\n"));
        Assert::true(str_contains($frame, "event: execution\n"));
        Assert::true(str_contains($frame, '"message":"hello"'));
        Assert::true(str_contains($frame, '"stream":"stderr"'));
        $this->removeDir($root);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
