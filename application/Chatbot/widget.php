<?php
declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    require_once dirname(__DIR__) . '/bootstrap.php';
}

// Avoid duplicate launchers inside dashboard iframes (parent shell already hosts the widget).
if (function_exists('app_is_embedded') && app_is_embedded()) {
    return;
}
if (is_student()) {
    return;
}

require_once __DIR__ . '/service.php';

$chatbotAudience = (logged_in() && !is_student()) ? 'staff' : 'public';
$chatbotRole = $chatbotAudience === 'staff' ? chatbot_role_name() : 'guest';
$chatbotConfig = [
    'endpoint' => '/chatbot_api.php',
    'audience' => $chatbotAudience,
    'role' => $chatbotRole,
    'csrf_token' => csrf_token(),
    'assistant_name' => 'University AI Assistant',
    'provider_ready' => chatbot_provider_config()['configured'],
    'page_path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    'page_title' => function_exists('institution_name_inline') ? institution_name_inline() : 'UPSIS',
    'suggestions' => chatbot_suggestions($chatbotAudience, $chatbotRole, [
        'path' => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
        'title' => '',
    ]),
];
?>
<link rel="stylesheet" href="assets/chatbot.css?v=2">
<div id="upsisChatbotRoot" data-chatbot-root hidden></div>
<script>
window.UPSIS_CHATBOT = <?= json_encode($chatbotConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/chatbot.js?v=2" defer></script>
