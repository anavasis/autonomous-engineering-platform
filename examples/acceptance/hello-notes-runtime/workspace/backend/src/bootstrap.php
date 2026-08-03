<?php
declare(strict_types=1);
require __DIR__ . '/notes.php';
require __DIR__ . '/search.php';

header('Content-Type: application/json');
$dbFile = dirname(__DIR__) . '/db/notes.sqlite';
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec((string) file_get_contents(dirname(__DIR__) . '/db/schema.ddl'));

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$q = $_GET['q'] ?? null;

if ($method === 'GET' && $path === '/notes' && is_string($q) && $q !== '') {
    echo json_encode(['notes' => notes_search($db, $q)], JSON_THROW_ON_ERROR);
    exit;
}
if ($method === 'GET' && $path === '/notes') {
    echo json_encode(['notes' => notes_list($db)], JSON_THROW_ON_ERROR);
    exit;
}
if ($method === 'POST' && $path === '/notes') {
    $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $note = notes_create($db, (string) ($payload['title'] ?? ''), (string) ($payload['body'] ?? ''));
    echo json_encode(['note' => $note], JSON_THROW_ON_ERROR);
    exit;
}
if ($method === 'PUT' && preg_match('#^/notes/(\d+)$#', $path, $m)) {
    $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $note = notes_update($db, (int) $m[1], (string) ($payload['title'] ?? ''), (string) ($payload['body'] ?? ''));
    echo json_encode(['note' => $note], JSON_THROW_ON_ERROR);
    exit;
}
if ($method === 'DELETE' && preg_match('#^/notes/(\d+)$#', $path, $m)) {
    notes_delete($db, (int) $m[1]);
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    exit;
}
http_response_code(404);
echo json_encode(['error' => 'not_found'], JSON_THROW_ON_ERROR);
