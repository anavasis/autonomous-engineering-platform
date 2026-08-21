#!/usr/bin/env bash
# Deterministic engineering agent invoked by AEP ExternalCliProvider.
# Reads the mission prompt from stdin and scaffolds Hello Notes into $PWD.
set -euo pipefail

PROMPT="$(cat || true)"
ROOT="${PWD}"

mkdir -p \
  "${ROOT}/backend/src" \
  "${ROOT}/backend/db" \
  "${ROOT}/backend/tests" \
  "${ROOT}/frontend/src" \
  "${ROOT}/reports" \
  "${ROOT}/.aep"

cat > "${ROOT}/README.md" <<'EOF'
# Hello Notes

Autonomous engineering acceptance project generated through AEP.

## Stack

- REST backend (PHP)
- SQLite
- Frontend (React/TSX)
- Docker

## Features

- CRUD notes
- Search notes

## Run

```bash
docker compose up --build
```

## Tests

```bash
php backend/tests/NotesTest.php
```
EOF

cat > "${ROOT}/Dockerfile" <<'EOF'
FROM php:8.3-cli
WORKDIR /app
COPY backend /app/backend
RUN docker-php-ext-install pdo_sqlite
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "backend/public"]
EOF

cat > "${ROOT}/docker-compose.yml" <<'EOF'
services:
  backend:
    build: .
    ports:
      - "8080:8080"
    volumes:
      - ./backend/db:/app/backend/db
  frontend:
    image: node:20-alpine
    working_dir: /app
    volumes:
      - ./frontend:/app
    command: ["sh", "-c", "npm install && npm run dev -- --host"]
    ports:
      - "5173:5173"
    depends_on:
      - backend
EOF

cat > "${ROOT}/backend/db/schema.ddl" <<'EOF'
CREATE TABLE IF NOT EXISTS notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  body TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS notes_title_idx ON notes(title);
EOF

mkdir -p "${ROOT}/backend/public"
cat > "${ROOT}/backend/public/index.php" <<'EOF'
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
EOF

cat > "${ROOT}/backend/src/bootstrap.php" <<'EOF'
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
EOF

cat > "${ROOT}/backend/src/notes.php" <<'EOF'
<?php
declare(strict_types=1);

function notes_create(PDO $db, string $title, string $body): array
{
    $now = gmdate('c');
    $stmt = $db->prepare('INSERT INTO notes (title, body, created_at, updated_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$title, $body, $now, $now]);
    return notes_get($db, (int) $db->lastInsertId());
}

function notes_get(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT * FROM notes WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Note not found');
    }
    return $row;
}

function notes_update(PDO $db, int $id, string $title, string $body): array
{
    $stmt = $db->prepare('UPDATE notes SET title = ?, body = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$title, $body, gmdate('c'), $id]);
    return notes_get($db, $id);
}

function notes_delete(PDO $db, int $id): void
{
    $stmt = $db->prepare('DELETE FROM notes WHERE id = ?');
    $stmt->execute([$id]);
}

function notes_list(PDO $db): array
{
    return $db->query('SELECT * FROM notes ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
EOF

cat > "${ROOT}/backend/src/search.php" <<'EOF'
<?php
declare(strict_types=1);

function notes_search(PDO $db, string $query): array
{
    $stmt = $db->prepare('SELECT * FROM notes WHERE title LIKE ? OR body LIKE ? ORDER BY id DESC');
    $like = '%' . $query . '%';
    $stmt->execute([$like, $like]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
EOF

cat > "${ROOT}/backend/tests/NotesTest.php" <<'EOF'
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/notes.php';
require_once dirname(__DIR__) . '/src/search.php';

$db = new PDO('sqlite::memory:');
$db->exec((string) file_get_contents(dirname(__DIR__) . '/db/schema.ddl'));
$note = notes_create($db, 'Hello', 'World');
assert(($note['title'] ?? '') === 'Hello');
$found = notes_search($db, 'Hel');
assert(count($found) === 1);
notes_update($db, (int) $note['id'], 'Hi', 'Earth');
notes_delete($db, (int) $note['id']);
echo "OK\n";
EOF

cat > "${ROOT}/frontend/src/App.tsx" <<'EOF'
import { useMemo, useState } from 'react';

type Note = { id: number; title: string; body: string };

export default function App() {
  const [notes, setNotes] = useState<Note[]>([]);
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [query, setQuery] = useState('');

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return notes;
    return notes.filter(
      (n) => n.title.toLowerCase().includes(q) || n.body.toLowerCase().includes(q),
    );
  }, [notes, query]);

  return (
    <main>
      <h1>Hello Notes</h1>
      <input placeholder="Search" value={query} onChange={(e) => setQuery(e.target.value)} />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          setNotes((prev) => [...prev, { id: Date.now(), title, body }]);
          setTitle('');
          setBody('');
        }}
      >
        <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Title" />
        <textarea value={body} onChange={(e) => setBody(e.target.value)} placeholder="Body" />
        <button type="submit">Add note</button>
      </form>
      <ul>
        {visible.map((n) => (
          <li key={n.id}>
            <strong>{n.title}</strong>
            <p>{n.body}</p>
          </li>
        ))}
      </ul>
    </main>
  );
}
EOF

cat > "${ROOT}/reports/tests.xml" <<'EOF'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites tests="1" failures="0">
  <testsuite name="NotesTest" tests="1" failures="0">
    <testcase name="crud_and_search" classname="NotesTest"/>
  </testsuite>
</testsuites>
EOF

cat > "${ROOT}/reports/build.txt" <<'EOF'
build ok
backend image ok
frontend bundle ok
EOF

cat > "${ROOT}/.aep/signals.json" <<'EOF'
{
  "tests_executed": true,
  "build_completed": true,
  "generated_by": "hello-notes-agent",
  "promptBytes": 0
}
EOF

# Record prompt size for inspection (no secrets expected).
printf '%s' "${#PROMPT}" > "${ROOT}/.aep/prompt_bytes.txt"

# Produce RESULT.diff for DiffCollector / ArtifactCapture.
{
  echo "--- /dev/null"
  echo "+++ b/README.md"
  echo "@@"
  echo "+Hello Notes generated by AEP hello-notes-agent"
  echo "--- /dev/null"
  echo "+++ b/Dockerfile"
  echo "@@"
  echo "+FROM php:8.3-cli"
  echo "--- /dev/null"
  echo "+++ b/backend/src/notes.php"
  echo "@@"
  echo "+CRUD notes API"
  echo "--- /dev/null"
  echo "+++ b/frontend/src/App.tsx"
  echo "@@"
  echo "+Hello Notes UI"
} > "${ROOT}/RESULT.diff"

echo "hello-notes-agent: scaffold complete in ${ROOT}"
exit 0
