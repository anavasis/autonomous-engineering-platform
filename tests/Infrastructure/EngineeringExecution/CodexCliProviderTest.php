<?php

declare(strict_types=1);

namespace Tests\Infrastructure\EngineeringExecution;

use Aep\Application\EngineeringExecution\Model\PromptBundle;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Model\ProviderSessionRequest;
use Aep\Application\EngineeringExecution\Service\DiffCollector;
use Aep\Infrastructure\EngineeringExecution\Provider\CodexCliProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\ExternalCliProvider;
use Tests\Support\Assert;

final class CodexCliProviderTest
{
    public function test_health_unavailable_when_binary_missing(): void
    {
        $provider = new CodexCliProvider([
            'binary' => '/tmp/aep-missing-codex-' . bin2hex(random_bytes(4)),
        ]);
        Assert::true($provider instanceof ExternalCliProvider);
        Assert::same('codex', $provider->id());
        Assert::same('OpenAI Codex', $provider->displayName());
        Assert::same('unavailable', $provider->health()->status());
        Assert::true(str_contains($provider->health()->message(), 'Codex CLI binary not found'));
    }

    public function test_run_uses_exec_sandbox_and_prompt_argv(): void
    {
        $root = sys_get_temp_dir() . '/aep_codex_cli_' . bin2hex(random_bytes(4));
        $workspace = $root . '/workspace';
        mkdir($workspace . '/context/src', 0775, true);
        file_put_contents($workspace . '/PROMPT.md', "implement codex feature\n");

        // Fake codex: argv must be exec --sandbox workspace-write <prompt>
        $script = <<<'SH'
#!/bin/sh
set -e
printf '%s\n' "$@" > .aep_codex_argv.txt
if [ "$1" != "exec" ] || [ "$2" != "--sandbox" ] || [ "$3" != "workspace-write" ]; then
  echo "expected exec --sandbox workspace-write" >&2
  exit 9
fi
echo "codex-stdout"
echo "codex-stderr" >&2
mkdir -p context/src
printf 'codex-real-change\n' > context/src/codex_change.txt
cat > RESULT.diff <<'EOF'
--- a/src/codex_change.txt
+++ b/src/codex_change.txt
@@
+codex-real-change
EOF
exit 0
SH;
        $bin = $this->fakeBinary($script);

        try {
            $provider = new CodexCliProvider([
                'binary' => $bin,
                'useRepoCwd' => false,
            ]);
            $provider->start(new ProviderSessionRequest(
                'sess_codex_1',
                'msn_codex_1',
                'run_codex_1',
                'implement',
                new PromptBundle('sys', 'user objective'),
                $workspace,
                ['src/'],
                [],
                30,
            ));

            $events = $provider->poll('sess_codex_1', 0);
            $logs = [];
            foreach ($events as $event) {
                if ($event->type() === 'log') {
                    $logs[] = $event->message();
                }
            }
            Assert::true(in_array('Codex CLI starting', $logs, true));
            Assert::true(in_array('codex-stdout', $logs, true));
            Assert::true(in_array('codex-stderr', $logs, true));

            $result = $provider->collectResult('sess_codex_1');
            Assert::same(ProviderResult::SUCCEEDED, $result->status());
            Assert::same(0, $result->rawMeta()['exitCode'] ?? null);

            $argv = (string) file_get_contents($workspace . '/.aep_codex_argv.txt');
            Assert::true(str_contains($argv, "exec\n"));
            Assert::true(str_contains($argv, "--sandbox\n"));
            Assert::true(str_contains($argv, "workspace-write\n"));
            Assert::true(str_contains($argv, 'implement codex feature'));

            $diff = (new DiffCollector())->collect($workspace);
            Assert::true(is_string($diff['text'] ?? null) && $diff['text'] !== '');
        } finally {
            @unlink($bin);
            $this->removeDir($root);
        }
    }

    public function test_json_args_remain_configurable(): void
    {
        $root = sys_get_temp_dir() . '/aep_codex_cli_' . bin2hex(random_bytes(4));
        $workspace = $root . '/workspace';
        mkdir($workspace, 0775, true);
        $bin = $this->fakeBinary("#!/bin/sh\nprintf '%s\\n' \"$@\" > args.txt\nexit 0\n");

        try {
            $provider = new CodexCliProvider([
                'binary' => $bin,
                'useRepoCwd' => false,
                'promptViaStdin' => false,
                'args' => ['exec', '--sandbox', 'workspace-write', '--ephemeral'],
            ]);
            $provider->start(new ProviderSessionRequest(
                'sess_codex_args',
                'msn_codex_args',
                'run_codex_args',
                'implement',
                new PromptBundle('s', 'u'),
                $workspace,
                ['src/'],
                [],
                15,
            ));
            $result = $provider->collectResult('sess_codex_args');
            Assert::same(ProviderResult::SUCCEEDED, $result->status());
            $args = (string) file_get_contents($workspace . '/args.txt');
            Assert::true(str_contains($args, "exec\n"));
            Assert::true(str_contains($args, "--sandbox\n"));
            Assert::true(str_contains($args, "workspace-write\n"));
            Assert::true(str_contains($args, "--ephemeral\n"));
        } finally {
            @unlink($bin);
            $this->removeDir($root);
        }
    }

    private function fakeBinary(string $script): string
    {
        $path = sys_get_temp_dir() . '/aep_codex_bin_' . bin2hex(random_bytes(4)) . '.sh';
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
