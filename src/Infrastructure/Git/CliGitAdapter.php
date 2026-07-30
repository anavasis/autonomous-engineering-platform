<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Git;

use Aep\Application\Git\GitCheckoutRequest;
use Aep\Application\Git\GitCloneRequest;
use Aep\Application\Git\GitCreateBranchRequest;
use Aep\Application\Git\GitHealth;
use Aep\Application\Git\GitMetadata;
use Aep\Application\Git\GitOperationLog;
use Aep\Application\Git\GitOperationLogEntry;
use Aep\Application\Git\GitPort;
use Aep\Application\Git\GitRepoRequest;
use Aep\Application\Git\GitResult;

/**
 * Local Git CLI adapter implementing Application {@see GitPort}.
 *
 * Workspace layout, secret resolution, metadata cache, and operation logs
 * remain Infrastructure-only. Credentials never appear in metadata or logs.
 */
final class CliGitAdapter implements GitPort
{
    public function __construct(
        private readonly string $workspaceRoot,
        private readonly GitProcessRunner $runner = new GitProcessRunner(),
        private readonly SecretResolver $secrets = new MapSecretResolver(),
    ) {
        if (trim($this->workspaceRoot) === '') {
            throw new \InvalidArgumentException('workspaceRoot must be non-empty.');
        }
    }

    public function clone(GitCloneRequest $request): GitResult
    {
        $workspace = $this->workspace($request->projectId());
        $workspace->ensureLayout();

        if ($workspace->repositoryExists()) {
            $result = GitResult::rejected('Repository already exists for project.');
            $this->appendLog($workspace, $request->occurredAtUtc(), 'Clone', $result->status(), $result->message());

            return $result;
        }

        $secret = $this->secrets->resolve($request->secretVault(), $request->secretKey());
        $cloneUrl = $this->authenticatedUrl($request->repository(), $secret);
        $sanitized = RemoteUrlSanitizer::sanitize($request->repository());

        // Clone into a temp directory then move contents into repository/ so the
        // workspace path itself is the working tree (not a nested clone folder).
        $tempClone = $workspace->tempDir() . DIRECTORY_SEPARATOR . 'clone-' . bin2hex(random_bytes(4));
        $proc = $this->runner->run(['clone', '--', $cloneUrl, $tempClone], $workspace->tempDir());

        if ($proc['exitCode'] !== 0) {
            $this->removePath($tempClone);
            $message = RemoteUrlSanitizer::redactMessage(
                'git clone failed: ' . trim($proc['stderr'] !== '' ? $proc['stderr'] : $proc['stdout']),
                $secret,
            );
            $result = GitResult::failed($message);
            $this->appendLog($workspace, $request->occurredAtUtc(), 'Clone', $result->status(), $result->message());
            $this->writeMetadata($workspace, $sanitized, null, 'disconnected');

            return $result;
        }

        $this->moveCloneIntoRepository($tempClone, $workspace->repositoryDir());
        $this->refreshMetadataCache($workspace, $sanitized, null);
        $result = GitResult::succeeded('Repository cloned.', [
            'remoteUrl' => $sanitized,
            'branch' => $this->readCurrentBranch($workspace),
            'commit' => $this->readCurrentCommit($workspace),
        ]);
        $this->appendLog($workspace, $request->occurredAtUtc(), 'Clone', $result->status(), $result->message());

        return $result;
    }

    public function fetch(GitRepoRequest $request): GitResult
    {
        $workspace = $this->workspace($request->projectId());
        $workspace->ensureLayout();

        if (!$workspace->repositoryExists()) {
            $result = GitResult::rejected('Repository not cloned.');
            $this->appendLog($workspace, $request->occurredAtUtc(), 'Fetch', $result->status(), $result->message());

            return $result;
        }

        $remoteUrl = $this->configuredRemoteUrl($workspace);
        $secret = null;
        // Fetch uses whatever remote is configured; credentials are not re-injected from SecretRef
        // on fetch in MVP (clone establishes the remote). For HTTPS remotes without stored creds,
        // ls-remote/fetch may fail — that is reflected in health.remoteReachable.
        $proc = $this->runner->run(['fetch', '--all', '--prune'], $workspace->repositoryDir());
        if ($proc['exitCode'] !== 0) {
            $message = RemoteUrlSanitizer::redactMessage(
                'git fetch failed: ' . trim($proc['stderr'] !== '' ? $proc['stderr'] : $proc['stdout']),
                $secret,
            );
            $result = GitResult::failed($message);
            $this->appendLog($workspace, $request->occurredAtUtc(), 'Fetch', $result->status(), $result->message());
            $this->refreshMetadataCache($workspace, $remoteUrl, null);

            return $result;
        }

        $this->refreshMetadataCache($workspace, $remoteUrl, $request->occurredAtUtc());
        $result = GitResult::succeeded('Fetch completed.', [
            'lastFetchAt' => $request->occurredAtUtc(),
        ]);
        $this->appendLog($workspace, $request->occurredAtUtc(), 'Fetch', $result->status(), $result->message());

        return $result;
    }

    public function status(GitRepoRequest $request): GitResult
    {
        $workspace = $this->workspace($request->projectId());
        if (!$workspace->repositoryExists()) {
            return GitResult::rejected('Repository not cloned.');
        }

        $proc = $this->runner->run(['status', '--porcelain'], $workspace->repositoryDir());
        if ($proc['exitCode'] !== 0) {
            return GitResult::failed(
                'git status failed: ' . trim($proc['stderr'] !== '' ? $proc['stderr'] : $proc['stdout']),
            );
        }

        $porcelain = trim($proc['stdout']);
        $clean = $porcelain === '';

        return GitResult::succeeded($clean ? 'Working tree clean.' : 'Working tree dirty.', [
            'clean' => $clean,
            'porcelain' => $porcelain,
            'branch' => $this->readCurrentBranch($workspace),
            'commit' => $this->readCurrentCommit($workspace),
        ]);
    }

    public function currentBranch(GitRepoRequest $request): GitResult
    {
        $workspace = $this->workspace($request->projectId());
        if (!$workspace->repositoryExists()) {
            return GitResult::rejected('Repository not cloned.');
        }

        $branch = $this->readCurrentBranch($workspace);
        if ($branch === '') {
            return GitResult::failed('Unable to determine current branch.');
        }

        return GitResult::succeeded('Current branch resolved.', [
            'branch' => $branch,
            'commit' => $this->readCurrentCommit($workspace),
        ]);
    }

    public function checkout(GitCheckoutRequest $request): GitResult
    {
        $workspace = $this->workspace($request->projectId());
        $workspace->ensureLayout();

        if (!$workspace->repositoryExists()) {
            $result = GitResult::rejected('Repository not cloned.');
            $this->appendLog($workspace, $request->occurredAtUtc(), 'Checkout', $result->status(), $result->message());

            return $result;
        }

        $proc = $this->runner->run(['checkout', $request->branch()], $workspace->repositoryDir());

        if ($proc['exitCode'] !== 0) {
            $message = 'git checkout failed: ' . trim($proc['stderr'] !== '' ? $proc['stderr'] : $proc['stdout']);
            $result = GitResult::failed($message);
            $this->appendLog($workspace, $request->occurredAtUtc(), 'Checkout', $result->status(), $result->message());

            return $result;
        }

        $remoteUrl = $this->configuredRemoteUrl($workspace);
        $meta = $workspace->readJson($workspace->metadataFile());
        $lastFetchAt = is_array($meta) && isset($meta['lastFetchAt']) && is_string($meta['lastFetchAt'])
            ? $meta['lastFetchAt']
            : null;
        $this->refreshMetadataCache($workspace, $remoteUrl, $lastFetchAt);

        $result = GitResult::succeeded('Checked out branch.', [
            'branch' => $this->readCurrentBranch($workspace),
            'commit' => $this->readCurrentCommit($workspace),
        ]);
        $this->appendLog($workspace, $request->occurredAtUtc(), 'Checkout', $result->status(), $result->message());

        return $result;
    }

    public function createBranch(GitCreateBranchRequest $request): GitResult
    {
        $workspace = $this->workspace($request->projectId());
        $workspace->ensureLayout();

        if (!$workspace->repositoryExists()) {
            $result = GitResult::rejected('Repository not cloned.');
            $this->appendLog($workspace, $request->occurredAtUtc(), 'CreateBranch', $result->status(), $result->message());

            return $result;
        }

        $args = $request->checkout()
            ? ['checkout', '-b', $request->branch()]
            : ['branch', '--', $request->branch()];

        $proc = $this->runner->run($args, $workspace->repositoryDir());
        if ($proc['exitCode'] !== 0) {
            $message = 'git create branch failed: ' . trim($proc['stderr'] !== '' ? $proc['stderr'] : $proc['stdout']);
            $result = GitResult::failed($message);
            $this->appendLog($workspace, $request->occurredAtUtc(), 'CreateBranch', $result->status(), $result->message());

            return $result;
        }

        $remoteUrl = $this->configuredRemoteUrl($workspace);
        $meta = $workspace->readJson($workspace->metadataFile());
        $lastFetchAt = is_array($meta) && isset($meta['lastFetchAt']) && is_string($meta['lastFetchAt'])
            ? $meta['lastFetchAt']
            : null;
        $this->refreshMetadataCache($workspace, $remoteUrl, $lastFetchAt);

        $result = GitResult::succeeded('Branch created.', [
            'branch' => $request->branch(),
            'checkedOut' => $request->checkout(),
            'currentBranch' => $this->readCurrentBranch($workspace),
            'commit' => $this->readCurrentCommit($workspace),
        ]);
        $this->appendLog($workspace, $request->occurredAtUtc(), 'CreateBranch', $result->status(), $result->message());

        return $result;
    }

    public function health(GitRepoRequest $request): GitHealth
    {
        $workspace = $this->workspace($request->projectId());
        $connected = $workspace->repositoryExists();
        if (!$connected) {
            return new GitHealth(false, false, false, '', '', null);
        }

        $branch = $this->readCurrentBranch($workspace);
        $commit = $this->readCurrentCommit($workspace);
        $clean = $this->isWorkingTreeClean($workspace);
        $remoteReachable = $this->isRemoteReachable($workspace);

        $meta = $workspace->readJson($workspace->metadataFile());
        $lastFetchAt = is_array($meta) && isset($meta['lastFetchAt']) && is_string($meta['lastFetchAt'])
            ? $meta['lastFetchAt']
            : null;

        return new GitHealth($connected, $remoteReachable, $clean, $branch, $commit, $lastFetchAt);
    }

    public function metadata(GitRepoRequest $request): GitMetadata
    {
        $workspace = $this->workspace($request->projectId());
        $workspace->ensureLayout();
        $data = $workspace->readJson($workspace->metadataFile()) ?? [];

        return new GitMetadata(
            is_string($data['currentBranch'] ?? null) ? $data['currentBranch'] : '',
            is_string($data['currentCommitSha'] ?? null) ? $data['currentCommitSha'] : '',
            is_string($data['remoteUrl'] ?? null) ? $data['remoteUrl'] : '',
            isset($data['lastFetchAt']) && is_string($data['lastFetchAt']) ? $data['lastFetchAt'] : null,
            is_string($data['repositoryHealth'] ?? null) ? $data['repositoryHealth'] : 'unknown',
        );
    }

    public function operationLog(GitRepoRequest $request): GitOperationLog
    {
        $workspace = $this->workspace($request->projectId());
        $workspace->ensureLayout();
        $path = $workspace->operationLogFile();
        if (!is_file($path)) {
            return new GitOperationLog([]);
        }

        $entries = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return new GitOperationLog([]);
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            $entries[] = new GitOperationLogEntry(
                is_string($decoded['timestamp'] ?? null) ? $decoded['timestamp'] : '',
                is_string($decoded['operation'] ?? null) ? $decoded['operation'] : '',
                is_string($decoded['result'] ?? null) ? $decoded['result'] : '',
                is_string($decoded['message'] ?? null) ? $decoded['message'] : '',
            );
        }

        return new GitOperationLog($entries);
    }

    private function workspace(string $projectId): ProjectWorkspace
    {
        return new ProjectWorkspace($this->workspaceRoot, $projectId);
    }

    private function authenticatedUrl(string $repository, ?string $secret): string
    {
        if ($secret === null || $secret === '') {
            return $repository;
        }

        $parts = parse_url($repository);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $repository;
        }

        $scheme = $parts['scheme'];
        if ($scheme === 'file' || $scheme === 'git') {
            return $repository;
        }

        $user = rawurlencode('x-access-token');
        $pass = rawurlencode($secret);
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . '://' . $user . ':' . $pass . '@' . $host . $port . $path . $query . $fragment;
    }

    private function moveCloneIntoRepository(string $tempClone, string $repositoryDir): void
    {
        if (!is_dir($tempClone)) {
            throw new \RuntimeException('Clone directory missing after git clone.');
        }

        // repositoryDir already exists empty; move children into it.
        $items = scandir($tempClone);
        if ($items === false) {
            throw new \RuntimeException('Unable to read clone directory.');
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $tempClone . DIRECTORY_SEPARATOR . $item;
            $to = $repositoryDir . DIRECTORY_SEPARATOR . $item;
            if (!rename($from, $to)) {
                throw new \RuntimeException('Unable to move cloned content into repository workspace.');
            }
        }

        $this->removePath($tempClone);
    }

    private function removePath(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removePath($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
    }

    private function readCurrentBranch(ProjectWorkspace $workspace): string
    {
        $proc = $this->runner->run(['rev-parse', '--abbrev-ref', 'HEAD'], $workspace->repositoryDir());
        if ($proc['exitCode'] !== 0) {
            return '';
        }

        return trim($proc['stdout']);
    }

    private function readCurrentCommit(ProjectWorkspace $workspace): string
    {
        $proc = $this->runner->run(['rev-parse', 'HEAD'], $workspace->repositoryDir());
        if ($proc['exitCode'] !== 0) {
            return '';
        }

        return trim($proc['stdout']);
    }

    private function isWorkingTreeClean(ProjectWorkspace $workspace): bool
    {
        $proc = $this->runner->run(['status', '--porcelain'], $workspace->repositoryDir());

        return $proc['exitCode'] === 0 && trim($proc['stdout']) === '';
    }

    private function isRemoteReachable(ProjectWorkspace $workspace): bool
    {
        $proc = $this->runner->run(['ls-remote', '--heads', 'origin'], $workspace->repositoryDir());

        return $proc['exitCode'] === 0;
    }

    private function configuredRemoteUrl(ProjectWorkspace $workspace): string
    {
        $proc = $this->runner->run(['remote', 'get-url', 'origin'], $workspace->repositoryDir());
        if ($proc['exitCode'] !== 0) {
            $meta = $workspace->readJson($workspace->metadataFile());
            if (is_array($meta) && isset($meta['remoteUrl']) && is_string($meta['remoteUrl'])) {
                return RemoteUrlSanitizer::sanitize($meta['remoteUrl']);
            }

            return '';
        }

        return RemoteUrlSanitizer::sanitize(trim($proc['stdout']));
    }

    private function refreshMetadataCache(
        ProjectWorkspace $workspace,
        string $remoteUrl,
        ?string $lastFetchAt,
    ): void {
        $branch = $this->readCurrentBranch($workspace);
        $commit = $this->readCurrentCommit($workspace);
        $clean = $this->isWorkingTreeClean($workspace);
        $reachable = $this->isRemoteReachable($workspace);
        $connected = $workspace->repositoryExists();

        $health = 'disconnected';
        if ($connected && $reachable && $clean) {
            $health = 'healthy';
        } elseif ($connected) {
            $health = 'degraded';
        }

        $existing = $workspace->readJson($workspace->metadataFile()) ?? [];
        if ($lastFetchAt === null && isset($existing['lastFetchAt']) && is_string($existing['lastFetchAt'])) {
            $lastFetchAt = $existing['lastFetchAt'];
        }

        $this->writeMetadata(
            $workspace,
            RemoteUrlSanitizer::sanitize($remoteUrl),
            $lastFetchAt,
            $health,
            $branch,
            $commit,
        );
    }

    private function writeMetadata(
        ProjectWorkspace $workspace,
        string $remoteUrl,
        ?string $lastFetchAt,
        string $repositoryHealth,
        string $currentBranch = '',
        string $currentCommitSha = '',
    ): void {
        $workspace->ensureLayout();
        $workspace->writeJson($workspace->metadataFile(), [
            'currentBranch' => $currentBranch,
            'currentCommitSha' => $currentCommitSha,
            'remoteUrl' => RemoteUrlSanitizer::sanitize($remoteUrl),
            'lastFetchAt' => $lastFetchAt,
            'repositoryHealth' => $repositoryHealth,
        ]);
    }

    private function appendLog(
        ProjectWorkspace $workspace,
        string $timestamp,
        string $operation,
        string $result,
        string $message,
    ): void {
        $workspace->ensureLayout();
        $safeMessage = RemoteUrlSanitizer::redactMessage($message);
        $entry = json_encode([
            'timestamp' => $timestamp,
            'operation' => $operation,
            'result' => $result,
            'message' => $safeMessage,
        ], JSON_UNESCAPED_SLASHES);
        if ($entry === false) {
            return;
        }
        file_put_contents($workspace->operationLogFile(), $entry . "\n", FILE_APPEND | LOCK_EX);
    }
}
