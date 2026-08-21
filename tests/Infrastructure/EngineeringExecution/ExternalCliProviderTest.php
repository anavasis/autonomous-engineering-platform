<?php

declare(strict_types=1);

namespace Tests\Infrastructure\EngineeringExecution;

use Aep\Application\EngineeringExecution\Model\PromptBundle;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Model\ProviderSessionRequest;
use Aep\Infrastructure\EngineeringExecution\Provider\CursorCliProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\ExternalCliProvider;
use Tests\Support\Assert;

final class ExternalCliProviderTest
{
    public function test_generic_external_cli_run_and_events(): void
    {
        $root = sys_get_temp_dir() . '/aep_ext_cli_' . bin2hex(random_bytes(4));
        $workspace = $root . '/workspace';
        mkdir($workspace, 0775, true);
        $bin = $this->fakeBinary("#!/bin/sh\necho ext-out\necho ext-err >&2\nexit 0\n");

        try {
            $provider = new ExternalCliProvider([
                'id' => 'ext_demo',
                'displayName' => 'Demo CLI',
                'label' => 'Demo CLI',
                'binary' => $bin,
                'useRepoCwd' => false,
                'promptViaStdin' => true,
            ]);
            Assert::same('ok', $provider->health()->status());

            $provider->start(new ProviderSessionRequest(
                'sess_ext_1',
                'msn_ext_1',
                'run_ext_1',
                'implement',
                new PromptBundle('s', 'u'),
                $workspace,
                ['src/'],
                [],
                20,
            ));
            $events = $provider->poll('sess_ext_1', 0);
            $messages = [];
            foreach ($events as $event) {
                if ($event->type() === 'log') {
                    $messages[] = $event->message();
                }
            }
            Assert::true(in_array('Demo CLI starting', $messages, true));
            Assert::true(in_array('ext-out', $messages, true));
            Assert::true(in_array('ext-err', $messages, true));

            $result = $provider->collectResult('sess_ext_1');
            Assert::same(ProviderResult::SUCCEEDED, $result->status());
            Assert::same(0, $result->rawMeta()['exitCode'] ?? null);
            Assert::true(str_contains($result->message(), 'Demo CLI completed'));
        } finally {
            @unlink($bin);
            $this->removeDir($root);
        }
    }

    public function test_cursor_provider_is_thin_external_subclass(): void
    {
        $provider = new CursorCliProvider(['binary' => '/tmp/aep-missing-' . bin2hex(random_bytes(3))]);
        Assert::true($provider instanceof ExternalCliProvider);
        Assert::same('cursor', $provider->id());
        Assert::same('Cursor Agent', $provider->displayName());
        Assert::same('unavailable', $provider->health()->status());
        Assert::true(str_contains($provider->health()->message(), 'Cursor CLI binary not found'));
    }

    private function fakeBinary(string $script): string
    {
        $path = sys_get_temp_dir() . '/aep_ext_bin_' . bin2hex(random_bytes(4)) . '.sh';
        file_put_contents($path, $script);
        chmod($path, 0755);

        return $path;
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
