<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionControl\Http;

final class JsonResponse
{
    /**
     * @param array<string, mixed>|list<mixed> $data
     * @param array<string, string> $headers
     */
    public static function send(mixed $data, int $status = 200, array $headers = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            header('X-Frame-Options: DENY');
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function problem(string $title, int $status, string $detail = '', ?string $type = null): void
    {
        self::send([
            'type' => $type ?? 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail !== '' ? $detail : $title,
        ], $status);
    }
}
