<?php
declare(strict_types=1);

function chatbot_conversation_get(int $id, string $ownerKey): ?array
{
    chatbot_ensure_tables();
    $stmt = db()->prepare('SELECT * FROM chatbot_conversation WHERE id_conversation = ? AND owner_key = ? LIMIT 1');
    $stmt->execute([$id, $ownerKey]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function chatbot_conversation_list(string $ownerKey, int $limit = 30): array
{
    chatbot_ensure_tables();
    $limit = max(1, min(50, $limit));
    $stmt = db()->prepare(
        'SELECT id_conversation, title, audience, role_name, page_path, created_at, updated_at
         FROM chatbot_conversation
         WHERE owner_key = ?
         ORDER BY updated_at DESC
         LIMIT ' . $limit
    );
    $stmt->execute([$ownerKey]);
    return $stmt->fetchAll() ?: [];
}

function chatbot_conversation_create(string $ownerKey, string $audience, string $role, string $title, ?string $pagePath): int
{
    chatbot_ensure_tables();
    $title = mb_substr(trim($title) !== '' ? trim($title) : 'Nouvelle conversation', 0, 180);
    db()->prepare(
        'INSERT INTO chatbot_conversation (owner_key, audience, role_name, title, page_path)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$ownerKey, $audience, $role, $title, $pagePath]);
    return (int) db()->lastInsertId();
}

function chatbot_conversation_rename(int $id, string $ownerKey, string $title): bool
{
    $title = mb_substr(trim($title), 0, 180);
    if ($title === '') {
        return false;
    }
    $stmt = db()->prepare(
        'UPDATE chatbot_conversation SET title = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id_conversation = ? AND owner_key = ?'
    );
    $stmt->execute([$title, $id, $ownerKey]);
    return $stmt->rowCount() > 0;
}

function chatbot_conversation_delete(int $id, string $ownerKey): bool
{
    chatbot_ensure_tables();
    $pdo = db();
    $conv = chatbot_conversation_get($id, $ownerKey);
    if (!$conv) {
        return false;
    }
    $pdo->prepare('DELETE FROM chatbot_message WHERE id_conversation = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM chatbot_conversation WHERE id_conversation = ? AND owner_key = ?')->execute([$id, $ownerKey]);
    return true;
}

function chatbot_conversation_clear_all(string $ownerKey): int
{
    chatbot_ensure_tables();
    $pdo = db();
    $ids = $pdo->prepare('SELECT id_conversation FROM chatbot_conversation WHERE owner_key = ?');
    $ids->execute([$ownerKey]);
    $count = 0;
    foreach ($ids->fetchAll() as $row) {
        $id = (int) $row['id_conversation'];
        $pdo->prepare('DELETE FROM chatbot_message WHERE id_conversation = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM chatbot_conversation WHERE id_conversation = ? AND owner_key = ?')->execute([$id, $ownerKey]);
        $count++;
    }
    return $count;
}

function chatbot_messages(int $conversationId, string $ownerKey, int $limit = 80): array
{
    if (!chatbot_conversation_get($conversationId, $ownerKey)) {
        return [];
    }
    $limit = max(1, min(120, $limit));
    $stmt = db()->prepare(
        'SELECT id_message, role_name, content, citations_json, created_at
         FROM chatbot_message
         WHERE id_conversation = ?
         ORDER BY id_message ASC
         LIMIT ' . $limit
    );
    $stmt->execute([$conversationId]);
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['citations'] = [];
        if (!empty($row['citations_json'])) {
            $decoded = json_decode((string) $row['citations_json'], true);
            if (is_array($decoded)) {
                $row['citations'] = $decoded;
            }
        }
        unset($row['citations_json']);
    }
    return $rows;
}

function chatbot_message_add(int $conversationId, string $role, string $content, ?array $citations = null): int
{
    $json = $citations ? json_encode($citations, JSON_UNESCAPED_UNICODE) : null;
    db()->prepare(
        'INSERT INTO chatbot_message (id_conversation, role_name, content, citations_json) VALUES (?, ?, ?, ?)'
    )->execute([$conversationId, $role, $content, $json]);
    db()->prepare('UPDATE chatbot_conversation SET updated_at = CURRENT_TIMESTAMP WHERE id_conversation = ?')
        ->execute([$conversationId]);
    return (int) db()->lastInsertId();
}

function chatbot_feedback_save(int $messageId, string $ownerKey, string $rating): bool
{
    if (!in_array($rating, ['up', 'down'], true)) {
        return false;
    }
    chatbot_ensure_tables();
    $check = db()->prepare(
        'SELECT m.id_message
         FROM chatbot_message m
         JOIN chatbot_conversation c ON c.id_conversation = m.id_conversation
         WHERE m.id_message = ? AND c.owner_key = ? LIMIT 1'
    );
    $check->execute([$messageId, $ownerKey]);
    if (!$check->fetch()) {
        return false;
    }
    db()->prepare(
        'INSERT INTO chatbot_feedback (id_message, owner_key, rating) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE rating = VALUES(rating)'
    )->execute([$messageId, $ownerKey, $rating]);
    return true;
}

function chatbot_title_from_message(string $message): string
{
    $clean = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
    if ($clean === '') {
        return 'Nouvelle conversation';
    }
    return mb_substr($clean, 0, 72);
}
