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
