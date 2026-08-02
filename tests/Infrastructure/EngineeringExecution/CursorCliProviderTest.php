<?php

declare(strict_types=1);

namespace Tests\Infrastructure\EngineeringExecution;

use Aep\Application\EngineeringExecution\Model\PromptBundle;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Model\ProviderSessionRequest;
use Aep\Application\EngineeringExecution\Service\DiffCollector;
use Aep\Infrastructure\EngineeringExecution\Provider\CursorCliProvider;
use Tests\Support\Assert;

final class CursorCliProviderTest
{
    public function test_health_unavailable_when_binary_missing(): void
    {
        $provider = new CursorCliProvider([
            'id' => 'cursor',
            'displayName' => 'Cursor Agent',
            'binary' => '/tmp/aep-missing-cursor-binary-' . bin2hex(random_bytes(4)),
        ]);
        $health = $provider->health();
        Assert::same('unavailable', $health->status());
        Assert::same(false, $health->isAvailable());
    }

    public function test_health_ok_when_binary_executable(): void
    {
        $bin = $this->fakeBinary('#!/bin/sh\nexit 0\n');
        try {
            $provider = new CursorCliProvider([
                'id' => 'cursor',
                'displayName' => 'Cursor Agent',
                'binary' => $bin,
            ]);
            $health = $provider->health();
            Assert::same('ok', $health->status());
            Assert::true($health->isAvailable());
        } finally {
            @unlink($bin);
        }
    }

    public function test_run_captures_streams_and_leaves_workspace_for_diffcollector(): void
    {
        $root = sys_get_temp_dir() . '/aep_cursor_cli_' . bin2hex(random_bytes(4));
        $workspace = $root . '/workspace';
        mkdir($workspace . '/context/src', 0775, true);
        file_put_contents($workspace . '/PROMPT.md', "system rules\n\n---\n\nimplement feature\n");

        $script = <<<'SH'
#!/bin/sh
set -e
PROMPT="$(cat)"
printf '%s' "$PROMPT" > .aep_prompt_in.txt
echo "cursor-stdout-line"
echo "cursor-stderr-line" >&2
mkdir -p context/src
printf 'cursor-real-change\n' > context/src/cursor_change.txt
cat > RESULT.diff <<'EOF'
--- a/src/cursor_change.txt
+++ b/src/cursor_change.txt
@@
+cursor-real-change
EOF
exit 0
SH;
        $bin = $this->fakeBinary($script);

        try {
            $provider = new CursorCliProvider([
                'id' => 'cursor',
                'displayName' => 'Cursor Agent',
                'binary' => $bin,
                'useRepoCwd' => false,
                'promptViaStdin' => true,
            ]);

            $request = new ProviderSessionRequest(
                'sess_cursor_1',
                'msn_cursor_1',
                'run_cursor_1',
                'implement',
                new PromptBundle('sys', 'user objective'),
                $workspace,
                ['src/'],
                [],
                30,
            );
            $provider->start($request);
            $events = $provider->poll('sess_cursor_1', 0);
            $types = array_map(static fn ($e) => $e->type(), $events);
            Assert::true(in_array('provider.started', $types, true));
            Assert::true(in_array('log', $types, true));
            Assert::true(in_array('provider.completed', $types, true));

            $logMessages = [];
            foreach ($events as $event) {
                if ($event->type() === 'log') {
                    $logMessages[] = $event->message();
                }
            }
            Assert::true(in_array('cursor-stdout-line', $logMessages, true));
            Assert::true(in_array('cursor-stderr-line', $logMessages, true));

            $result = $provider->collectResult('sess_cursor_1');
            Assert::same(ProviderResult::SUCCEEDED, $result->status());
            Assert::same(0, $result->rawMeta()['exitCode'] ?? null);
            Assert::true(isset($result->rawMeta()['elapsedSeconds']));
            Assert::true(str_contains((string) ($result->rawMeta()['stdout'] ?? ''), 'cursor-stdout-line'));
            Assert::true(str_contains((string) ($result->rawMeta()['stderr'] ?? ''), 'cursor-stderr-line'));
            Assert::true(is_file($workspace . '/context/src/cursor_change.txt'));
            Assert::true(is_file($workspace . '/.aep_prompt_in.txt'));
            Assert::true(str_contains((string) file_get_contents($workspace . '/.aep_prompt_in.txt'), 'implement feature'));

            $diff = (new DiffCollector())->collect($workspace);
            Assert::true(is_string($diff['text'] ?? null) && $diff['text'] !== '');
            Assert::true(count($diff['files'] ?? []) >= 1);
        } finally {
            @unlink($bin);
            $this->removeDir($root);
        }
    }

    public function test_nonzero_exit_fails_without_mutating_provider_side_files(): void
    {
        $root = sys_get_temp_dir() . '/aep_cursor_cli_' . bin2hex(random_bytes(4));
        $workspace = $root . '/workspace';
        mkdir($workspace, 0775, true);
        $bin = $this->fakeBinary("#!/bin/sh\necho boom >&2\nexit 7\n");

        try {
            $provider = new CursorCliProvider([
                'id' => 'cursor',
                'binary' => $bin,
                'useRepoCwd' => false,
            ]);
            $provider->start(new ProviderSessionRequest(
                'sess_fail',
                'msn_fail',
                'run_fail',
                'implement',
                new PromptBundle('s', 'u'),
                $workspace,
                ['src/'],
                [],
                15,
            ));
            $result = $provider->collectResult('sess_fail');
            Assert::same(ProviderResult::FAILED, $result->status());
            Assert::same(7, $result->rawMeta()['exitCode'] ?? null);
            Assert::true(!is_file($workspace . '/context/src/cursor_change.txt'));
        } finally {
            @unlink($bin);
            $this->removeDir($root);
        }
    }

    public function test_uses_repo_subdirectory_when_configured_and_present(): void
    {
        $root = sys_get_temp_dir() . '/aep_cursor_cli_' . bin2hex(random_bytes(4));
        $workspace = $root . '/workspace';
        mkdir($workspace . '/repo', 0775, true);
        $bin = $this->fakeBinary("#!/bin/sh\npwd > cwd.txt\nexit 0\n");

        try {
            $provider = new CursorCliProvider([
                'id' => 'cursor',
                'binary' => $bin,
                'useRepoCwd' => true,
                'promptViaStdin' => true,
            ]);
            $provider->start(new ProviderSessionRequest(
                'sess_repo',
                'msn_repo',
                'run_repo',
                'implement',
                new PromptBundle('s', 'u'),
                $workspace,
                ['src/'],
                [],
                15,
            ));
            $result = $provider->collectResult('sess_repo');
            Assert::same(ProviderResult::SUCCEEDED, $result->status());
            Assert::true(is_file($workspace . '/repo/cwd.txt'));
            $cwd = trim((string) file_get_contents($workspace . '/repo/cwd.txt'));
            Assert::true(str_ends_with($cwd, '/repo') || str_ends_with($cwd, '\\repo'));
        } finally {
            @unlink($bin);
            $this->removeDir($root);
        }
    }

    private function fakeBinary(string $script): string
    {
        $path = sys_get_temp_dir() . '/aep_cursor_bin_' . bin2hex(random_bytes(4)) . '.sh';
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
