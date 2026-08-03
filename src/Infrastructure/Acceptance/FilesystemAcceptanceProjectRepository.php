<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Acceptance;

use Aep\Application\Acceptance\Model\AcceptanceProject;
use Aep\Application\Acceptance\Port\AcceptanceProjectRepository;

/**
 * Loads AcceptanceProject definitions from directories containing project.json.
 *
 * Layout:
 *   {root}/{name}/project.json
 */
final class FilesystemAcceptanceProjectRepository implements AcceptanceProjectRepository
{
    /** @var list<string> */
    private array $roots;

    /**
     * @param list<string>|string $roots
     */
    public function __construct(array|string $roots)
    {
        $this->roots = [];
        foreach (is_array($roots) ? $roots : [$roots] as $root) {
            if (!is_string($root) || trim($root) === '') {
                continue;
            }
            $this->roots[] = rtrim($root, "/\\");
        }
        if ($this->roots === []) {
            throw new \InvalidArgumentException('At least one acceptance project root is required.');
        }
    }

    public function get(string $name): ?AcceptanceProject
    {
        $name = trim($name);
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
            return null;
        }
        foreach ($this->roots as $root) {
            $path = $root . '/' . $name . '/project.json';
            if (!is_file($path)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data)) {
                continue;
            }
            if (!isset($data['name']) || !is_string($data['name'])) {
                $data['name'] = $name;
            }

            return AcceptanceProject::fromArray($data);
        }

        return null;
    }

    public function all(): array
    {
        $found = [];
        foreach ($this->roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $entries = scandir($root) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (isset($found[$entry])) {
                    continue;
                }
                $project = $this->get($entry);
                if ($project !== null) {
                    $found[$entry] = $project;
                }
            }
        }

        return array_values($found);
    }

    public function projectDirectory(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
            return null;
        }
        foreach ($this->roots as $root) {
            $dir = $root . '/' . $name;
            if (is_dir($dir) && is_file($dir . '/project.json')) {
                return $dir;
            }
        }

        return null;
    }
}
