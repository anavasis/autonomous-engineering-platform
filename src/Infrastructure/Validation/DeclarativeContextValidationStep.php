<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Validation;

use Aep\Application\Validation\ValidationOutcome;
use Aep\Application\Validation\ValidationRequest;
use Aep\Application\Validation\ValidationStep;

/**
 * Requires real provider execution evidence and changed-file scope compliance.
 * Does not claim that application tests ran.
 */
final class DeclarativeContextValidationStep implements ValidationStep
{
    public const ID = 'declarative_context';

    public function id(): string
    {
        return self::ID;
    }

    public function run(ValidationRequest $request): ValidationOutcome
    {
        $providerId = $this->nonEmptyString($request->contextValue('providerId'))
            ?? $this->nonEmptyString($request->contextValue('routedProviderId'));
        if ($providerId === null) {
            return ValidationOutcome::failed(self::ID, 'providerId is required for validation');
        }

        $sessionId = $this->nonEmptyString($request->contextValue('sessionId'));
        if ($sessionId === null) {
            return ValidationOutcome::failed(self::ID, 'sessionId is required for validation');
        }

        $workspacePath = $this->nonEmptyString($request->contextValue('workspacePath'));
        if ($workspacePath === null) {
            return ValidationOutcome::failed(self::ID, 'workspacePath is required for validation');
        }

        $filesChanged = $request->contextValue('filesChanged');
        if (!is_array($filesChanged) || $filesChanged === []) {
            return ValidationOutcome::failed(self::ID, 'filesChanged must be a non-empty list');
        }

        $patchId = $this->nonEmptyString($request->contextValue('patchId'));
        if ($patchId === null) {
            return ValidationOutcome::failed(self::ID, 'patchId is required for validation');
        }

        $patchStatus = $this->nonEmptyString($request->contextValue('patchStatus'));
        if ($patchStatus === null) {
            return ValidationOutcome::failed(self::ID, 'patchStatus is required for validation');
        }

        $declared = $request->contextValue('declaredPaths');
        if (!is_array($declared) || $declared === []) {
            $declared = $request->contextValue('allowedPaths');
        }
        if (!is_array($declared) || $declared === []) {
            return ValidationOutcome::failed(
                self::ID,
                'declaredPaths must be a non-empty list in validation context'
            );
        }

        /** @var list<array{path: string, directory: bool}> $allowed */
        $allowed = [];
        foreach ($declared as $path) {
            if (!is_string($path) || trim($path) === '') {
                return ValidationOutcome::failed(
                    self::ID,
                    'declaredPaths entries must be non-empty strings'
                );
            }
            $entry = $this->normalizeAllowEntry($path);
            if ($entry === null) {
                return ValidationOutcome::failed(self::ID, 'declaredPaths contains an invalid path');
            }
            $allowed[] = $entry;
        }

        foreach ($filesChanged as $changed) {
            if (!is_string($changed) || trim($changed) === '') {
                return ValidationOutcome::failed(self::ID, 'filesChanged entries must be non-empty strings');
            }
            $normalizedChanged = $this->normalizeFilePath($changed);
            if ($normalizedChanged === null) {
                return ValidationOutcome::failed(self::ID, 'filesChanged contains a traversal or invalid path');
            }
            if (!$this->isAllowed($normalizedChanged, $allowed)) {
                return ValidationOutcome::failed(
                    self::ID,
                    'changed path is outside allowed paths: ' . $normalizedChanged
                );
            }
        }

        return ValidationOutcome::passed(
            self::ID,
            'real provider execution, workspace, changed-file scope, and patch evidence verified'
        );
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @return array{path: string, directory: bool}|null
     */
    private function normalizeAllowEntry(string $path): ?array
    {
        $raw = str_replace('\\', '/', trim($path));
        $directory = str_ends_with($raw, '/');
        $normalized = $this->normalizeFilePath($raw);
        if ($normalized === null) {
            return null;
        }

        return ['path' => $normalized, 'directory' => $directory];
    }

    private function normalizeFilePath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }
        $parts = explode('/', $path);
        $out = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $out[] = $part;
        }
        if ($out === []) {
            return null;
        }

        return implode('/', $out);
    }

    /**
     * @param list<array{path: string, directory: bool}> $allowed
     */
    private function isAllowed(string $changed, array $allowed): bool
    {
        foreach ($allowed as $entry) {
            if ($changed === $entry['path']) {
                return true;
            }
            if ($entry['directory'] && str_starts_with($changed, $entry['path'] . '/')) {
                return true;
            }
        }

        return false;
    }
}
