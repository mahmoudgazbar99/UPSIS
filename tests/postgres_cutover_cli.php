<?php
declare(strict_types=1);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/application/bootstrap.php';

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
    echo ($ok ? 'ok  ' : 'FAIL ') . $label . PHP_EOL;
};

$pdo = db();
$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$check($driver === 'pgsql', 'application connection uses pgsql (' . $driver . ')');
$students = (int) $pdo->query('SELECT COUNT(*) FROM etudiant')->fetchColumn();
$check($students === 125, 'student count is 125 (' . $students . ')');
$arabic = (string) $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'institution_city_ar'")->fetchColumn();
$check($arabic === 'قابس', 'Arabic city is preserved (' . $arabic . ')');
$paid = (string) $pdo->query('SELECT COALESCE(SUM(total_paye), 0) FROM finance_solde')->fetchColumn();
$check($paid === '27000.00', 'finance balance sum keeps cents (' . $paid . ')');
$logo = strlen(pg_blob($pdo->query("SELECT content FROM institution_logo WHERE asset_key = 'logo'")->fetchColumn()));
$check($logo === 452303, 'logo binary length is preserved (' . $logo . ')');

$pdo->beginTransaction();
$pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)')->execute(['pg_probe', 'probe']);
$inserted = (int) $pdo->lastInsertId();
$pdo->rollBack();
$left = (int) $pdo->query("SELECT COUNT(*) FROM system_settings WHERE setting_key = 'pg_probe'")->fetchColumn();
$check($inserted > 0 && $left === 0, 'insert id ' . $inserted . ' rolls back');

$second = db_connect();
$pdo->beginTransaction();
$pdo->query('SELECT id_setting FROM system_settings WHERE setting_key = \'currency_code\' FOR UPDATE');
$second->exec("SET lock_timeout = '1s'");
$blocked = false;
try {
    $second->beginTransaction();
    $second->query('SELECT id_setting FROM system_settings WHERE setting_key = \'currency_code\' FOR UPDATE');
} catch (PDOException $exception) {
    $blocked = str_contains($exception->getMessage(), 'lock timeout');
}
$pdo->rollBack();
if ($second->inTransaction()) {
    $second->rollBack();
}
$check($blocked, 'row lock times out for a second connection');

echo $failures === [] ? "ALL PASSED\n" : 'FAILED ' . count($failures) . "\n";
exit($failures === [] ? 0 : 1);
