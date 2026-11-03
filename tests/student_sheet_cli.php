<?php
declare(strict_types=1);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/application/bootstrap.php';
require dirname(__DIR__) . '/application/support/student_sheet.php';

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
    echo ($ok ? 'ok  ' : 'FAIL ') . $label . PHP_EOL;
};

$check(student_sheet_header_key('CIN ou passeport') === 'cin_passeport', 'french identity header');
$check(student_sheet_header_key('Date de naissance') === 'date_naissance', 'french birth header');
$check(student_sheet_date('02/11/2004') === '2004-11-02', 'day month year date');
$check(student_sheet_sexe('F') === 'Femme' && student_sheet_bac('Mathématiques') === 'Math', 'common aliases');

$binary = student_sheet_xlsx([
    ['Nom', 'Prénom', 'CIN ou passeport'],
    ['Ben Ali', 'Sarra', '12345678'],
    ['', '', ''],
]);
$path = tempnam(sys_get_temp_dir(), 'sheet');
file_put_contents($path, $binary);
$rows = student_sheet_read($path, 'xlsx');
@unlink($path);
$check(count($rows) === 1 && ($rows[0]['nom'] ?? '') === 'Ben Ali' && ($rows[0]['cin_passeport'] ?? '') === '12345678', 'xlsx round trip keeps one student');
$check(!isset($rows[0]['email']), 'missing email column is absent rather than invented');

if ($failures) {
    fwrite(STDERR, count($failures) . " student sheet check(s) failed\n");
    exit(1);
}
echo "STUDENT SHEET CHECKS PASSED\n";
