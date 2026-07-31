<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringWorkspace\Model;

final class WorkspaceMounts
{
    /**
     * @param list<array{artifactId: string, kind: string, checksum: string}> $artifacts
     * @param list<string> $contextFiles
     */
    public function __construct(
        private ?string $promptHash = null,
        private array $artifacts = [],
        private array $contextFiles = [],
        private int $redactions = 0,
    ) {
    }

    public function promptHash(): ?string
    {
        return $this->promptHash;
    }

    public function setPromptHash(string $hash): void
    {
        $this->promptHash = $hash;
    }

    /** @return list<array{artifactId: string, kind: string, checksum: string}> */
    public function artifacts(): array
    {
        return $this->artifacts;
    }

    /** @param list<array{artifactId: string, kind: string, checksum: string}> $artifacts */
    public function setArtifacts(array $artifacts): void
    {
        $this->artifacts = $artifacts;
    }

    /** @return list<string> */
    public function contextFiles(): array
    {
        return $this->contextFiles;
    }

    /** @param list<string> $files */
    public function setContextFiles(array $files): void
    {
        $this->contextFiles = $files;
    }

    public function setRedactions(int $count): void
    {
        $this->redactions = max(0, $count);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'promptHash' => $this->promptHash,
            'artifacts' => $this->artifacts,
            'contextFiles' => $this->contextFiles,
            'redactions' => $this->redactions,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $artifacts = [];
        if (isset($data['artifacts']) && is_array($data['artifacts'])) {
            foreach ($data['artifacts'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $artifacts[] = [
                    'artifactId' => is_string($row['artifactId'] ?? null) ? $row['artifactId'] : '',
                    'kind' => is_string($row['kind'] ?? null) ? $row['kind'] : '',
                    'checksum' => is_string($row['checksum'] ?? null) ? $row['checksum'] : '',
                ];
            }
        }
        $context = [];
        if (isset($data['contextFiles']) && is_array($data['contextFiles'])) {
            foreach ($data['contextFiles'] as $f) {
                if (is_string($f)) {
                    $context[] = $f;
                }
            }
        }

        return new self(
            isset($data['promptHash']) && is_string($data['promptHash']) ? $data['promptHash'] : null,
            $artifacts,
            $context,
            is_int($data['redactions'] ?? null) ? $data['redactions'] : 0,
        );
    }
}
