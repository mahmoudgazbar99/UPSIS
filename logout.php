<?php
require_once __DIR__ . '/config.php';
$next = app_logout_current();
header('Location: ' . $next);
exit;
