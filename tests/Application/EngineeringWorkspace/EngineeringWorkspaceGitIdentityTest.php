<?php

declare(strict_types=1);

namespace Tests\Application\EngineeringWorkspace;

use Aep\Application\EngineeringExecution\Model\PromptBundle;
use Aep\Application\EngineeringWorkspace\Model\EngineeringWorkspace;
use Aep\Application\EngineeringWorkspace\Port\GitWorkspaceCheckout;
use Aep\Application\EngineeringWorkspace\Service\EngineeringWorkspaceService;
use Aep\Infrastructure\EngineeringWorkspace\Store\FilesystemEngineeringWorkspaceStore;
use Aep\Infrastructure\EngineeringWorkspace\Store\JsonWorkspaceSettingsStore;
use Tests\Support\Assert;

final class EngineeringWorkspaceGitIdentityTest
{
    public function test_project_bound_behavior_unchanged(): void
    {
        $root = $this->tempDir();
        try {
            $seen = [];
            $git = new class ($seen) implements GitWorkspaceCheckout {
                /** @param array<string, mixed> $seen */
                public function __construct(private array &$seen)
                {
                }

                public function materialize(EngineeringWorkspace $workspace, array $spec): array
                {
                    $this->seen = $spec;
                    $repo = rtrim($workspace->rootPath(), '/') . '/repo';
                    if (!is_dir($repo)) {
                        mkdir($repo, 0775, true);
                    }
                    file_put_contents($repo . '/.git', 'gitdir: fake');

                    return [
                        'mode' => 'worktree',
                        'branch' => 'aep/x',
                        'headSha' => 'abc123',
                        'baseBranch' => 'main',
                        'provider' => $spec['provider'] ?? 'github',
                        'repository' => $spec['repository'] ?? '',
                    ];
                }
            };
            $service = new EngineeringWorkspaceService(
                new FilesystemEngineeringWorkspaceStore($root . '/workspaces'),
                new JsonWorkspaceSettingsStore($root . '/workspaces'),
                $git,
            );
            $ws = $service->ensureForSession(
                'esess_bound',
                'msn_bound',
                'run_bound',
                new PromptBundle('s', 'u'),
                [],
                ['README.md'],
                'proj_real_1',
                [],
                ['provider' => 'github', 'repository' => 'anavasis/aep-codex-smoke'],
            );
            Assert::same('proj_real_1', $ws->projectId());
            Assert::same('proj_real_1', $seen['projectId'] ?? null);
            Assert::same('worktree', $ws->git()['mode'] ?? null);
            Assert::true(is_dir($ws->rootPath() . '/repo'));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_no_project_mission_receives_safe_deterministic_identity(): void
    {
        $root = $this->tempDir();
        try {
            $seen = [];
            $git = new class ($seen) implements GitWorkspaceCheckout {
                /** @param array<string, mixed> $seen */
                public function __construct(private array &$seen)
                {
                }

                public function materialize(EngineeringWorkspace $workspace, array $spec): array
                {
                    $this->seen = $spec;
                    $repo = rtrim($workspace->rootPath(), '/') . '/repo';
                    if (!is_dir($repo)) {
                        mkdir($repo, 0775, true);
                    }
                    if (!is_dir($repo . '/.git')) {
                        mkdir($repo . '/.git', 0775, true);
                    }

                    return [
                        'mode' => 'worktree',
                        'branch' => 'aep/y',
                        'headSha' => 'def456',
                        'baseBranch' => 'main',
                    ];
                }
            };
            $service = new EngineeringWorkspaceService(
                new FilesystemEngineeringWorkspaceStore($root . '/workspaces'),
                new JsonWorkspaceSettingsStore($root . '/workspaces'),
                $git,
            );
            $ws = $service->ensureForSession(
                'esess_noproj',
                'msn_noproj',
                'run_noproj',
                new PromptBundle('s', 'u'),
                [],
                ['README.md'],
                null,
                [],
                ['provider' => 'github', 'repository' => 'anavasis/aep-codex-smoke'],
            );
            Assert::same(null, $ws->projectId());
            $expected = 'repo_' . hash('sha256', 'github:anavasis/aep-codex-smoke');
            Assert::same($expected, $seen['projectId'] ?? null);
            Assert::true(str_starts_with((string) ($seen['projectId'] ?? ''), 'repo_'));
            Assert::true(!str_contains((string) ($seen['projectId'] ?? ''), 'anavasis'));
            Assert::true(!str_contains((string) ($seen['projectId'] ?? ''), '/'));
            Assert::same('worktree', $ws->git()['mode'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_provider_cwd_repo_exists_after_materialization(): void
    {
        $root = $this->tempDir();
        try {
            $git = new class implements GitWorkspaceCheckout {
                public function materialize(EngineeringWorkspace $workspace, array $spec): array
                {
                    $repo = rtrim($workspace->rootPath(), '/') . '/repo';
                    if (!is_dir($repo)) {
                        mkdir($repo, 0775, true);
                    }
                    if (!is_dir($repo . '/.git')) {
                        mkdir($repo . '/.git', 0775, true);
                    }
                    file_put_contents($repo . '/README.md', "# AEP Codex Smoke Test\n");

                    return ['mode' => 'worktree', 'branch' => 'aep/z', 'headSha' => 'aaa'];
                }
            };
            $service = new EngineeringWorkspaceService(
                new FilesystemEngineeringWorkspaceStore($root . '/workspaces'),
                new JsonWorkspaceSettingsStore($root . '/workspaces'),
                $git,
            );
            $ws = $service->ensureForSession(
                'esess_cwd',
                'msn_cwd',
                'run_cwd',
                new PromptBundle('s', 'u'),
                [],
                ['README.md'],
                null,
                [],
                ['provider' => 'github', 'repository' => 'anavasis/aep-codex-smoke'],
            );
            $repo = $ws->rootPath() . '/repo';
            Assert::true(is_dir($repo));
            Assert::true(is_dir($repo . '/.git'));
            // ExternalCliProvider/Codex resolve cwd to workspace/repo when present (useRepoCwd).
            Assert::true(is_dir($repo));
        } finally {
            $this->removeDir($root);
        }
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/aep_ws_git_' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);

        return $dir;
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
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
