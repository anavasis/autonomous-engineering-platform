<?php

declare(strict_types=1);

function notes_search(PDO $db, string $query): array
{
    $stmt = $db->prepare('SELECT * FROM notes WHERE title LIKE ? OR body LIKE ? ORDER BY id DESC');
    $like = '%' . $query . '%';
    $stmt->execute([$like, $like]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
