<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Model;

final class RetrievalQuery
{
    /**
     * @param list<string> $tags
     * @param list<string> $paths
     * @param list<string> $kinds
     * @param list<string> $tokens
     */
    public function __construct(
        private string $objective,
        private ?string $projectId = null,
        private ?string $missionId = null,
        private array $tags = [],
        private array $paths = [],
        private array $kinds = [],
        private array $tokens = [],
        private int $limit = 12,
        private string $mode = 'pre_execution',
    ) {
        if ($this->tokens === []) {
            $this->tokens = self::tokenize($objective);
        }
    }

    public function objective(): string
    {
        return $this->objective;
    }

    public function projectId(): ?string
    {
        return $this->projectId;
    }

    public function missionId(): ?string
    {
        return $this->missionId;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return $this->kinds;
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return $this->tokens;
    }

    public function limit(): int
    {
        return max(1, min(50, $this->limit));
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $kinds = [];
        if (isset($data['kinds']) && is_array($data['kinds'])) {
            foreach ($data['kinds'] as $k) {
                if (is_string($k)) {
                    $kinds[] = $k;
                }
            }
        }
        $tags = [];
        if (isset($data['tags']) && is_array($data['tags'])) {
            foreach ($data['tags'] as $t) {
                if (is_string($t)) {
                    $tags[] = $t;
                }
            }
        }
        $paths = [];
        if (isset($data['paths']) && is_array($data['paths'])) {
            foreach ($data['paths'] as $p) {
                if (is_string($p)) {
                    $paths[] = $p;
                }
            }
        }

        return new self(
            is_string($data['objective'] ?? null) ? $data['objective'] : '',
            is_string($data['projectId'] ?? null) ? $data['projectId'] : null,
            is_string($data['missionId'] ?? null) ? $data['missionId'] : null,
            $tags,
            $paths,
            $kinds,
            [],
            is_int($data['limit'] ?? null) ? $data['limit'] : 12,
            is_string($data['mode'] ?? null) ? $data['mode'] : 'pre_execution',
        );
    }

    /** @return list<string> */
    public static function tokenize(string $text): array
    {
        $parts = preg_split('/\W+/', strtolower($text)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if (strlen($p) >= 4) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }
}
