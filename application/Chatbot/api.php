<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/service.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = (string) ($_GET['action'] ?? '');
$body = chatbot_json_body();
if ($action === '' && isset($body['action'])) {
    $action = (string) $body['action'];
}
$action = preg_replace('/[^a-z_]/', '', $action) ?: '';

try {
    if (in_array($action, ['chat', 'new', 'rename', 'delete', 'clear', 'feedback'], true)) {
        chatbot_verify_request_csrf();
    }

    $audienceHint = (string) ($body['audience'] ?? $_GET['audience'] ?? 'public');
    $audience = chatbot_resolve_audience($audienceHint);
    $role = $audience === 'staff' ? chatbot_role_name() : 'guest';
    $ownerKey = chatbot_owner_key($audience);

    if ($action === 'bootstrap') {
        $pagePath = chatbot_sanitize_user_text((string) ($_GET['page_path'] ?? ''), 255);
        $pageTitle = chatbot_sanitize_user_text((string) ($_GET['page_title'] ?? ''), 180);
        $config = chatbot_provider_config();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'audience' => $audience,
            'role' => $role,
            'csrf_token' => csrf_token(),
            'provider_ready' => (bool) $config['configured'],
            'assistant_name' => 'University AI Assistant',
            'suggestions' => chatbot_suggestions($audience, $role, ['path' => $pagePath, 'title' => $pageTitle]),
            'conversations' => chatbot_conversation_list($ownerKey, 20),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'history') {
        chatbot_rate_limit_or_fail('hist:' . $ownerKey, 60, 600);
        $id = (int) ($_GET['conversation_id'] ?? $body['conversation_id'] ?? 0);
        header('Content-Type: application/json; charset=utf-8');
        if ($id <= 0 || !chatbot_conversation_get($id, $ownerKey)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'conversation_id' => $id,
            'messages' => chatbot_messages($id, $ownerKey),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'chat') {
        chatbot_rate_limit_or_fail('chat:' . $ownerKey . ':' . chatbot_client_ip(), 30, 600);
        $result = chatbot_handle_chat($body);
        header('Content-Type: application/json; charset=utf-8');
        if (!$result['ok']) {
            http_response_code(400);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'new') {
        $id = chatbot_conversation_create($ownerKey, $audience, $role, 'Nouvelle conversation', null);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'conversation_id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'rename') {
        $id = (int) ($body['conversation_id'] ?? 0);
        $title = chatbot_sanitize_user_text((string) ($body['title'] ?? ''), 180);
        $ok = chatbot_conversation_rename($id, $ownerKey, $title);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($body['conversation_id'] ?? 0);
        $ok = chatbot_conversation_delete($id, $ownerKey);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'clear') {
        $count = chatbot_conversation_clear_all($ownerKey);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'deleted' => $count], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'feedback') {
        $messageId = (int) ($body['message_id'] ?? 0);
        $rating = (string) ($body['rating'] ?? '');
        $ok = chatbot_feedback_save($messageId, $ownerKey, $rating);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'unknown_action'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('chatbot_api: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
