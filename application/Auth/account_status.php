<?php
declare(strict_types=1);

$status = current_account_session_status();
$studentSpace = is_student();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($status === 'suspended' || $status === 'revoked') {
    app_logout_current($studentSpace);
    echo json_encode([
        'authenticated' => false,
        'suspended' => $status === 'suspended',
    ], JSON_THROW_ON_ERROR);
    exit;
}

echo json_encode([
    'authenticated' => $status === 'active',
    'suspended' => false,
], JSON_THROW_ON_ERROR);
