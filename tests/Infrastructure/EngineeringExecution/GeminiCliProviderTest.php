<?php

declare(strict_types=1);

namespace Tests\Infrastructure\EngineeringExecution;

use Aep\Application\EngineeringExecution\Model\PromptBundle;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Model\ProviderSessionRequest;
use Aep\Application\EngineeringExecution\Service\DiffCollector;
use Aep\Infrastructure\EngineeringExecution\Provider\ExternalCliProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\GeminiCliProvider;
use Tests\Support\Assert;

final class GeminiCliProviderTest
{
    public function test_health_unavailable_when_binary_missing(): void
    {
        $provider = new GeminiCliProvider([
            'binary' => '/tmp/aep-missing-gemini-' . bin2hex(random_bytes(4)),
        ]);
        Assert::true($provider instanceof ExternalCliProvider);
        Assert::same('gemini-cli', $provider->id());
        Assert::same('Gemini CLI', $provider->displayName());
        Assert::same('unavailable', $provider->health()->status());
        Assert::true(str_contains($provider->health()->message(), 'Gemini CLI binary not found'));
    }

    public function test_run_uses_print_flag_and_prompt_argv(): void
    {
        $root = sys_get_temp_dir() . '/aep_gemini_cli_' . bin2hex(random_bytes(4));
        $workspace = $root . '/workspace';
        mkdir($workspace . '/context/src', 0775, true);
        file_put_contents($workspace . '/PROMPT.md', "implement gemini feature\n");

        // Fake gemini: argv[1] must be -p, argv[2] is prompt; write RESULT.diff for DiffCollector.
        $script = <<<'SH'
#!/bin/sh
set -e
printf '%s\n' "$@" > .aep_gemini_argv.txt
if [ "$1" != "-p" ]; then
  echo "expected -p as first arg" >&2
  exit 9
fi
echo "gemini-stdout"
echo "gemini-stderr" >&2
mkdir -p context/src
printf 'gemini-real-change\n' > context/src/gemini_change.txt
cat > RESULT.diff <<'EOF'
--- a/src/gemini_change.txt
+++ b/src/gemini_change.txt
@@
+gemini-real-change
EOF
exit 0
SH;
        $bin = $this->fakeBinary($script);

        try {
            $provider = new GeminiCliProvider([
                'binary' => $bin,
                'useRepoCwd' => false,
            ]);
            $provider->start(new ProviderSessionRequest(
                'sess_gemini_1',
                'msn_gemini_1',
                'run_gemini_1',
                'implement',
                new PromptBundle('sys', 'user objective'),
                $workspace,
                ['src/'],
                [],
                30,
            ));

            $events = $provider->poll('sess_gemini_1', 0);
            $logs = [];
            foreach ($events as $event) {
                if ($event->type() === 'log') {
                    $logs[] = $event->message();
                }
            }
            Assert::true(in_array('Gemini CLI starting', $logs, true));
            Assert::true(in_array('gemini-stdout', $logs, true));
            Assert::true(in_array('gemini-stderr', $logs, true));

            $result = $provider->collectResult('sess_gemini_1');
            Assert::same(ProviderResult::SUCCEEDED, $result->status());
            Assert::same(0, $result->rawMeta()['exitCode'] ?? null);

            $argv = (string) file_get_contents($workspace . '/.aep_gemini_argv.txt');
            Assert::true(str_contains($argv, "-p\n"));
            Assert::true(str_contains($argv, 'implement gemini feature'));

            $diff = (new DiffCollector())->collect($workspace);
            Assert::true(is_string($diff['text'] ?? null) && $diff['text'] !== '');
        } finally {
            @unlink($bin);
            $this->removeDir($root);
        }
    }

    public function test_json_args_remain_configurable(): void
    {
        $root = sys_get_temp_dir() . '/aep_gemini_cli_' . bin2hex(random_bytes(4));
        $workspace = $root . '/workspace';
        mkdir($workspace, 0775, true);
        $bin = $this->fakeBinary("#!/bin/sh\nprintf '%s\\n' \"$@\" > args.txt\nexit 0\n");

        try {
            $provider = new GeminiCliProvider([
                'binary' => $bin,
                'useRepoCwd' => false,
                'promptViaStdin' => false,
                'args' => ['-p', '--yolo'],
            ]);
            $provider->start(new ProviderSessionRequest(
                'sess_gemini_args',
                'msn_gemini_args',
                'run_gemini_args',
                'implement',
                new PromptBundle('s', 'u'),
                $workspace,
                ['src/'],
                [],
                15,
            ));
            $result = $provider->collectResult('sess_gemini_args');
            Assert::same(ProviderResult::SUCCEEDED, $result->status());
            $args = (string) file_get_contents($workspace . '/args.txt');
            Assert::true(str_contains($args, "-p\n"));
            Assert::true(str_contains($args, "--yolo\n"));
        } finally {
            @unlink($bin);
            $this->removeDir($root);
        }
    }

    private function fakeBinary(string $script): string
    {
        $path = sys_get_temp_dir() . '/aep_gemini_bin_' . bin2hex(random_bytes(4)) . '.sh';
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
