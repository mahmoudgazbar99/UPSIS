<?php
require_once app_path('bootstrap.php');
$next = app_logout_current();
header('Location: ' . $next);
exit;
