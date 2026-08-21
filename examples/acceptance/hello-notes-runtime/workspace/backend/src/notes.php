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
