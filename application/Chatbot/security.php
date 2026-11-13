<?php
declare(strict_types=1);

function chatbot_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return preg_replace('/[^0-9a-fA-F:\.]/', '', $ip) ?: '0.0.0.0';
}

function chatbot_owner_key(string $audience): string
{
    if ($audience === 'staff' && logged_in() && !is_student()) {
        $role = (string) ($_SESSION['user_role'] ?? 'staff');
        $id = 0;
        if (is_admin()) {
            $id = (int) ($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
        } elseif (is_teacher()) {
            $id = current_teacher_id();
        } elseif (is_stage()) {
            $id = current_stage_id();
        } elseif (is_finance()) {
            $id = (int) ($_SESSION['finance_id'] ?? 0);
        } elseif (function_exists('is_scolarite') && is_scolarite()) {
            $id = (int) ($_SESSION['scolarite_id'] ?? 0);
        }
        return 'staff:' . $role . ':' . max(0, $id);
    }
    $sid = session_id() ?: 'anon';
    return 'public:' . hash('sha256', $sid . '|' . chatbot_client_ip());
}

function chatbot_verify_request_csrf(): void
{
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '') {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json) && isset($json['csrf_token'])) {
                $token = (string) $json['csrf_token'];
            }
        }
    }
    if ($token === '') {
        $token = (string) ($_POST['csrf_token'] ?? '');
    }
    if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $token)) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'csrf_invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function chatbot_rate_limit_or_fail(string $bucket, int $maxHits = 40, int $windowSeconds = 600): void
{
    chatbot_ensure_tables();
    $pdo = db();
    $key = substr($bucket, 0, 160);
    $row = $pdo->prepare('SELECT id_limit, hit_count, window_started FROM chatbot_rate_limit WHERE bucket_key = ? LIMIT 1');
    $row->execute([$key]);
    $existing = $row->fetch();
    $now = time();
    if (!$existing) {
        $pdo->prepare('INSERT INTO chatbot_rate_limit (bucket_key, window_started, hit_count) VALUES (?, CURRENT_TIMESTAMP, 1)')
            ->execute([$key]);
        return;
    }
    $started = strtotime((string) $existing['window_started']) ?: $now;
    if (($now - $started) > $windowSeconds) {
        $pdo->prepare('UPDATE chatbot_rate_limit SET window_started = CURRENT_TIMESTAMP, hit_count = 1 WHERE id_limit = ?')
            ->execute([(int) $existing['id_limit']]);
        return;
    }
    $hits = (int) $existing['hit_count'] + 1;
    if ($hits > $maxHits) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'rate_limited'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo->prepare('UPDATE chatbot_rate_limit SET hit_count = ? WHERE id_limit = ?')
        ->execute([$hits, (int) $existing['id_limit']]);
}

function chatbot_sanitize_user_text(string $text, int $maxLen = 4000): string
{
    $text = trim(str_replace("\0", '', $text));
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
    if (mb_strlen($text) > $maxLen) {
        $text = mb_substr($text, 0, $maxLen);
    }
    return $text;
}

function chatbot_looks_like_injection(string $text): bool
{
    $lower = mb_strtolower($text);
    $needles = [
        'ignore previous instructions',
        'ignore all instructions',
        'system prompt',
        'reveal the api key',
        'show me the api key',
        'exfiltrate',
        'bypass authorization',
        'disable safety',
        'pretend you are root',
        'dan mode',
        'developer mode',
        'oublie les instructions',
        'ignore les instructions',
        'révèle la clé',
        'revele la cle',
        'mode développeur',
    ];
    foreach ($needles as $needle) {
        if (str_contains($lower, $needle)) {
            return true;
        }
    }
    return false;
}

function chatbot_strip_secrets(string $text): string
{
    $patterns = [
        '/\bsk-[A-Za-z0-9_\-]{10,}\b/',
        '/\bCHATBOT_API_KEY\b\s*=\s*\S+/i',
        '/\bNEON_AI_GATEWAY_[A-Z0-9_]+\b\s*=\s*\S+/i',
        '/\bBearer\s+[A-Za-z0-9\-_\.]{20,}/i',
        '/\bpassword\s*[:=]\s*\S+/i',
    ];
    foreach ($patterns as $pattern) {
        $text = preg_replace($pattern, '[redacted]', $text) ?? $text;
    }
    return $text;
}

function chatbot_safe_markdown(string $text): string
{
    $text = chatbot_strip_secrets($text);
    // Never allow raw HTML from the model into the client payload unchecked.
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $text) ?? $text;
    $text = preg_replace('/on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $text) ?? $text;
    return $text;
}

function chatbot_json_body(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    $cached = is_array($decoded) ? $decoded : [];
    return $cached;
}
