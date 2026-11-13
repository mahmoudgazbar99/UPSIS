<?php
declare(strict_types=1);

function chatbot_knowledge_dir(): string
{
    return root_path('resources/chatbot/knowledge');
}

function chatbot_load_corpus(): array
{
    static $corpus = null;
    if ($corpus !== null) {
        return $corpus;
    }
    $corpus = [];
    $dir = chatbot_knowledge_dir();
    if (!is_dir($dir)) {
        return $corpus;
    }
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $raw = file_get_contents($file);
        $items = json_decode((string) $raw, true);
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $corpus[] = $item;
        }
    }
    return $corpus;
}

function chatbot_role_name(): string
{
    if (!logged_in() || is_student()) {
        return 'guest';
    }
    return (string) ($_SESSION['user_role'] ?? 'guest');
}

function chatbot_doc_allowed(array $doc, string $audience, string $role): bool
{
    $allowedAudience = $doc['audience'] ?? ['public'];
    if (!is_array($allowedAudience)) {
        $allowedAudience = [$allowedAudience];
    }
    if (!in_array($audience, $allowedAudience, true) && !in_array('staff', $allowedAudience, true) && $audience === 'staff') {
        // staff may read public docs
    }
    if ($audience === 'public' && !in_array('public', $allowedAudience, true)) {
        return false;
    }
    if ($audience === 'staff') {
        if (!in_array('staff', $allowedAudience, true) && !in_array('public', $allowedAudience, true)) {
            return false;
        }
        $roles = $doc['roles'] ?? null;
        if (is_array($roles) && $roles !== [] && $role !== 'admin' && !in_array($role, $roles, true)) {
            return false;
        }
    }
    return true;
}

function chatbot_tokenize(string $text): array
{
    $text = mb_strtolower($text);
    $text = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $text) ?? $text;
    $parts = preg_split('/\s+/u', $text) ?: [];
    $tokens = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if (mb_strlen($part) < 2) {
            continue;
        }
        $tokens[$part] = true;
    }
    return array_keys($tokens);
}

function chatbot_retrieve(string $query, string $audience, string $role, int $limit = 5): array
{
    $tokens = chatbot_tokenize($query);
    $hits = [];
    foreach (chatbot_load_corpus() as $doc) {
        if (!chatbot_doc_allowed($doc, $audience, $role)) {
            continue;
        }
        $hay = mb_strtolower(
            ($doc['title'] ?? '') . ' ' .
            ($doc['summary'] ?? '') . ' ' .
            ($doc['body'] ?? '') . ' ' .
            implode(' ', $doc['tags'] ?? [])
        );
        $score = 0;
        foreach ($tokens as $token) {
            if (str_contains($hay, $token)) {
                $score += 2;
            }
        }
        foreach (($doc['tags'] ?? []) as $tag) {
            if (str_contains(mb_strtolower((string) $query), mb_strtolower((string) $tag))) {
                $score += 3;
            }
        }
        if ($score <= 0) {
            continue;
        }
        $hits[] = ['score' => $score, 'doc' => $doc];
    }
    usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    $hits = array_slice($hits, 0, $limit);
    return array_map(static function (array $hit): array {
        $doc = $hit['doc'];
        return [
            'id' => (string) $doc['id'],
            'title' => (string) ($doc['title'] ?? ''),
            'summary' => (string) ($doc['summary'] ?? ''),
            'body' => (string) ($doc['body'] ?? ''),
            'links' => is_array($doc['links'] ?? null) ? $doc['links'] : [],
            'score' => $hit['score'],
        ];
    }, $hits);
}

function chatbot_format_retrieval_block(array $docs): string
{
    if ($docs === []) {
        return "No approved knowledge snippets matched.";
    }
    $chunks = [];
    foreach ($docs as $index => $doc) {
        $n = $index + 1;
        $links = [];
        foreach ($doc['links'] as $link) {
            if (!is_array($link)) {
                continue;
            }
            $href = (string) ($link['href'] ?? '');
            $label = (string) ($link['label'] ?? $href);
            if ($href === '' || !str_starts_with($href, '/')) {
                continue;
            }
            $links[] = $label . ' (' . $href . ')';
        }
        $chunks[] = "[KB{$n}] {$doc['title']}\n{$doc['body']}"
            . ($links ? ("\nLinks: " . implode('; ', $links)) : '');
    }
    return implode("\n\n", $chunks);
}
