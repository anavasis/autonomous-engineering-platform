<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Artifact;

use Aep\Application\Artifact\ArtifactManifest;

/**
 * JSON serializer for ArtifactManifest.
 */
final class ManifestSerializer
{
    public function encode(ArtifactManifest $manifest): string
    {
        try {
            return json_encode(
                $manifest->toArray(),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . "\n";
        } catch (\JsonException $e) {
            throw new \RuntimeException('Unable to encode artifact manifest.', 0, $e);
        }
    }

    public function decode(string $json): ArtifactManifest
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid artifact manifest JSON.', 0, $e);
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Artifact manifest must decode to an object.');
        }

        return ArtifactManifest::fromArray($data);
    }

    public function writeAtomic(string $path, ArtifactManifest $manifest): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create manifest directory: ' . $dir);
        }
        $payload = $this->encode($manifest);
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temp, $payload) === false) {
            throw new \RuntimeException('Unable to write temporary manifest: ' . $temp);
        }
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to replace manifest: ' . $path);
        }
    }

    public function readFile(string $path): ArtifactManifest
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Manifest not found: ' . $path);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read manifest: ' . $path);
        }

        return $this->decode($raw);
    }
}
