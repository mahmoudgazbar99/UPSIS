<?php
declare(strict_types=1);

/**
 * Allowlisted read-only tools. Never execute model-authored SQL.
 */
function chatbot_tool_registry(): array
{
    return [
        'list_modules' => [
            'description' => 'List application modules the current user may open.',
            'audience' => ['staff'],
            'handler' => 'chatbot_tool_list_modules',
        ],
        'count_students' => [
            'description' => 'Count students visible to the current staff role (admin/scolarité only).',
            'audience' => ['staff'],
            'roles' => ['admin', 'scolarite'],
            'handler' => 'chatbot_tool_count_students',
        ],
        'count_groups' => [
            'description' => 'Count academic groups.',
            'audience' => ['staff'],
            'roles' => ['admin', 'scolarite'],
            'handler' => 'chatbot_tool_count_groups',
        ],
        'page_help' => [
            'description' => 'Explain the current page from approved knowledge.',
            'audience' => ['public', 'staff'],
            'handler' => 'chatbot_tool_page_help',
        ],
    ];
}

function chatbot_tool_allowed(string $name, string $audience, string $role): bool
{
    $registry = chatbot_tool_registry();
    if (!isset($registry[$name])) {
        return false;
    }
    $meta = $registry[$name];
    $allowedAudience = $meta['audience'] ?? ['staff'];
    if (!in_array($audience, $allowedAudience, true)) {
        return false;
    }
    $roles = $meta['roles'] ?? null;
    if (is_array($roles) && $roles !== [] && $role !== 'admin' && !in_array($role, $roles, true)) {
        return false;
    }
    return true;
}

function chatbot_run_tool(string $name, array $args, string $audience, string $role, array $pageContext): array
{
    if (!chatbot_tool_allowed($name, $audience, $role)) {
        return ['ok' => false, 'error' => 'tool_forbidden'];
    }
    $handler = chatbot_tool_registry()[$name]['handler'];
    if (!is_callable($handler)) {
        return ['ok' => false, 'error' => 'tool_missing'];
    }
    return $handler($args, $audience, $role, $pageContext);
}

function chatbot_tool_list_modules(array $args, string $audience, string $role, array $pageContext): array
{
    $docs = array_values(array_filter(
        chatbot_load_corpus(),
        static fn (array $doc): bool => chatbot_doc_allowed($doc, 'staff', $role) && str_starts_with((string) ($doc['id'] ?? ''), 'mod-')
    ));
    $items = [];
    foreach ($docs as $doc) {
        $items[] = [
            'id' => $doc['id'],
            'title' => $doc['title'] ?? '',
            'summary' => $doc['summary'] ?? '',
            'links' => $doc['links'] ?? [],
        ];
    }
    return ['ok' => true, 'modules' => $items];
}

function chatbot_tool_count_students(array $args, string $audience, string $role, array $pageContext): array
{
    if (!role_can('students.view') && !is_admin()) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    $count = (int) db()->query('SELECT COUNT(*) FROM etudiant')->fetchColumn();
    return ['ok' => true, 'count' => $count];
}

function chatbot_tool_count_groups(array $args, string $audience, string $role, array $pageContext): array
{
    if (!is_admin() && !(function_exists('is_scolarite') && is_scolarite())) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    if (!db_table_exists(db(), 'groupe')) {
        return ['ok' => true, 'count' => 0];
    }
    $count = (int) db()->query('SELECT COUNT(*) FROM groupe')->fetchColumn();
    return ['ok' => true, 'count' => $count];
}

function chatbot_tool_page_help(array $args, string $audience, string $role, array $pageContext): array
{
    $path = (string) ($pageContext['path'] ?? ($args['path'] ?? ''));
    $path = strtok($path, '?') ?: $path;
    $base = basename($path);
    $query = $base . ' ' . (string) ($pageContext['title'] ?? '');
    $docs = chatbot_retrieve($query !== ' ' ? $query : 'plateforme', $audience, $role, 3);
    return ['ok' => true, 'path' => $base, 'docs' => $docs];
}

function chatbot_select_tools_for_message(string $message, string $audience, string $role): array
{
    $lower = mb_strtolower($message);
    $selected = [];
    if ($audience === 'staff' && (str_contains($lower, 'module') || str_contains($lower, 'où') || str_contains($lower, 'where'))) {
        $selected[] = 'list_modules';
    }
    if (preg_match('/\b(combien|how many|count|nombre)\b/u', $lower)) {
        if (str_contains($lower, 'étudiant') || str_contains($lower, 'etudiant') || str_contains($lower, 'student')) {
            $selected[] = 'count_students';
        }
        if (str_contains($lower, 'groupe') || str_contains($lower, 'group')) {
            $selected[] = 'count_groups';
        }
    }
    if (str_contains($lower, 'cette page') || str_contains($lower, 'this page') || str_contains($lower, 'current page') || str_contains($lower, 'page actuelle')) {
        $selected[] = 'page_help';
    }
    $out = [];
    foreach (array_unique($selected) as $name) {
        if (chatbot_tool_allowed($name, $audience, $role)) {
            $out[] = $name;
        }
    }
    return $out;
}
