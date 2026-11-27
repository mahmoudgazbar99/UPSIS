<?php
declare(strict_types=1);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/application/bootstrap.php';
require_once app_path('support/emploi_smart.php');

emploi_smart_ensure();
$pdo = db();
$tables = ['emploi_config', 'emploi_ressource', 'emploi_version', 'emploi_composante', 'emploi_session', 'emploi_generation_run', 'emploi_unscheduled'];
$ok = true;
foreach ($tables as $table) {
    try {
        $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        echo "ok  table $table\n";
    } catch (Throwable $e) {
        $ok = false;
        echo "FAIL table $table: " . $e->getMessage() . "\n";
    }
}
$config = emploi_smart_get_config();
echo ($config['morning_start'] === '08:15' ? 'ok  ' : 'FAIL ') . "default morning_start\n";
echo ($ok ? "ALL PASS\n" : "FAILURES\n");
exit($ok ? 0 : 1);
