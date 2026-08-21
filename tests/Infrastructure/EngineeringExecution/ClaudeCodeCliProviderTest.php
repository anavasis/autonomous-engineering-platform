<?php

declare(strict_types=1);

namespace Tests\Infrastructure\EngineeringExecution;

use Aep\Application\EngineeringExecution\Model\PromptBundle;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Model\ProviderSessionRequest;
use Aep\Application\EngineeringExecution\Service\DiffCollector;
use Aep\Infrastructure\EngineeringExecution\Provider\ClaudeCodeCliProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\ExternalCliProvider;
use Tests\Support\Assert;

final class ClaudeCodeCliProviderTest
{
    public function test_health_unavailable_when_binary_missing(): void
    {
        $provider = new ClaudeCodeCliProvider([
            'binary' => '/tmp/aep-missing-claude-' . bin2hex(random_bytes(4)),
        ]);
        Assert::true($provider instanceof ExternalCliProvider);
        Assert::same('claude-code', $provider->id());
        Assert::same('Claude Code', $provider->displayName());
        Assert::same('unavailable', $provider->health()->status());
        Assert::true(str_contains($provider->health()->message(), 'Claude Code CLI binary not found'));
    }

    public function test_run_uses_print_flag_and_prompt_argv(): void
    {
        $root = sys_get_temp_dir() . '/aep_claude_cli_' . bin2hex(random_bytes(4));
        $workspace = $root . '/workspace';
        mkdir($workspace . '/context/src', 0775, true);
        file_put_contents($workspace . '/PROMPT.md', "implement claude feature\n");

        // Fake claude: argv[1] must be -p, argv[2] is prompt; write RESULT.diff for DiffCollector.
        $script = <<<'SH'
#!/bin/sh
set -e
printf '%s\n' "$@" > .aep_claude_argv.txt
if [ "$1" != "-p" ]; then
  echo "expected -p as first arg" >&2
  exit 9
fi
echo "claude-stdout"
echo "claude-stderr" >&2
mkdir -p context/src
printf 'claude-real-change\n' > context/src/claude_change.txt
cat > RESULT.diff <<'EOF'
--- a/src/claude_change.txt
+++ b/src/claude_change.txt
@@
+claude-real-change
EOF
exit 0
SH;
        $bin = $this->fakeBinary($script);

        try {
            $provider = new ClaudeCodeCliProvider([
                'binary' => $bin,
                'useRepoCwd' => false,
            ]);
            $provider->start(new ProviderSessionRequest(
                'sess_claude_1',
                'msn_claude_1',
                'run_claude_1',
                'implement',
                new PromptBundle('sys', 'user objective'),
                $workspace,
                ['src/'],
                [],
                30,
            ));

            $events = $provider->poll('sess_claude_1', 0);
            $logs = [];
            foreach ($events as $event) {
                if ($event->type() === 'log') {
                    $logs[] = $event->message();
                }
            }
            Assert::true(in_array('Claude Code CLI starting', $logs, true));
            Assert::true(in_array('claude-stdout', $logs, true));
            Assert::true(in_array('claude-stderr', $logs, true));

            $result = $provider->collectResult('sess_claude_1');
            Assert::same(ProviderResult::SUCCEEDED, $result->status());
            Assert::same(0, $result->rawMeta()['exitCode'] ?? null);

            $argv = (string) file_get_contents($workspace . '/.aep_claude_argv.txt');
            Assert::true(str_contains($argv, "-p\n"));
            Assert::true(str_contains($argv, 'implement claude feature'));

            $diff = (new DiffCollector())->collect($workspace);
            Assert::true(is_string($diff['text'] ?? null) && $diff['text'] !== '');
        } finally {
            @unlink($bin);
            $this->removeDir($root);
        }
    }

    public function test_json_args_remain_configurable(): void
    {
        $root = sys_get_temp_dir() . '/aep_claude_cli_' . bin2hex(random_bytes(4));
        $workspace = $root . '/workspace';
        mkdir($workspace, 0775, true);
        $bin = $this->fakeBinary("#!/bin/sh\nprintf '%s\\n' \"$@\" > args.txt\nexit 0\n");

        try {
            $provider = new ClaudeCodeCliProvider([
                'binary' => $bin,
                'useRepoCwd' => false,
                'promptViaStdin' => false,
                'args' => ['-p', '--allowedTools', 'Read,Edit'],
            ]);
            $provider->start(new ProviderSessionRequest(
                'sess_claude_args',
                'msn_claude_args',
                'run_claude_args',
                'implement',
                new PromptBundle('s', 'u'),
                $workspace,
                ['src/'],
                [],
                15,
            ));
            $result = $provider->collectResult('sess_claude_args');
            Assert::same(ProviderResult::SUCCEEDED, $result->status());
            $args = (string) file_get_contents($workspace . '/args.txt');
            Assert::true(str_contains($args, "-p\n"));
            Assert::true(str_contains($args, "--allowedTools\n"));
            Assert::true(str_contains($args, "Read,Edit\n"));
        } finally {
            @unlink($bin);
            $this->removeDir($root);
        }
    }

    private function fakeBinary(string $script): string
    {
        $path = sys_get_temp_dir() . '/aep_claude_bin_' . bin2hex(random_bytes(4)) . '.sh';
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
