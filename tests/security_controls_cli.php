<?php
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/security_controls_cli.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

require dirname(__DIR__) . '/application/bootstrap.php';
require_once app_path('support/emploi_pdf.php');

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
    echo ($ok ? 'ok   ' : 'fail ') . $label . PHP_EOL;
};

$repair = (string) file_get_contents(app_path('Admin/fix_utf8.php'));
$repairAuth = strpos($repair, 'require_admin()');
$repairUpdate = strpos($repair, 'UPDATE ');
$check($repairAuth !== false && $repairUpdate !== false && $repairAuth < $repairUpdate, 'utf8 repair requires an administrator before any update');
$check(!preg_match('/echo\s[^;]*getMessage\s*\(/', $repair), 'utf8 repair does not print database errors');

$word = (string) file_get_contents(app_path('Documents/generate_word.php'));
$presence = (string) file_get_contents(app_path('Presence/generate_presence.php'));
$check(
    !preg_match('/echo\s[^;]*getMessage\s*\(/', $word) && !preg_match('/echo\s[^;]*getMessage\s*\(/', $presence),
    'certificate responses do not echo exception text'
);

$rootGuard = (string) file_get_contents(dirname(__DIR__) . '/.htaccess');
$check(str_contains($rootGuard, 'application') && str_contains($rootGuard, '[F,L]'), 'project root denies application, storage, and config when served by Apache');

$login = (string) file_get_contents(app_path('Auth/login.php'));
$studentLogin = (string) file_get_contents(app_path('Auth/connexion_etudiant.php'));
$check(str_contains($login, 'verify_csrf()') && str_contains($login, 'csrf_token'), 'staff login checks a CSRF token');
$check(str_contains($studentLogin, 'verify_csrf()') && str_contains($studentLogin, 'csrf_token'), 'student login checks a CSRF token');
$check(str_contains($login, 'auth_rate_limited') && str_contains($studentLogin, 'auth_rate_limited'), 'login failures are rate limited');

$bucket = 'security-test:' . bin2hex(random_bytes(4));
auth_bucket_clear('id:' . $bucket);
auth_bucket_clear('ip:' . auth_client_address());
$locked = false;
for ($attempt = 0; $attempt < 8; $attempt++) {
    auth_rate_fail($bucket);
    $locked = auth_rate_limited($bucket);
}
$check($locked, 'eight failures on one identity lock further attempts');
auth_rate_clear($bucket);
$check(!auth_bucket_locked('id:' . $bucket, 8), 'clearing the identity bucket removes the lock');

$plain = tempnam(sys_get_temp_dir(), 'upsis');
$pdf = tempnam(sys_get_temp_dir(), 'upsis');
if ($plain === false || $pdf === false) {
    $check(false, 'temporary files for PDF signature checks');
} else {
    file_put_contents($plain, "not a pdf\n");
    file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    $check(emploi_pdf_signature_ok($plain) === false, 'plain text is not accepted as a timetable PDF');
    unlink($plain);
    unlink($pdf);
}

try {
    formula_evaluate('note; system(1)', ['note' => 1]);
    $check(false, 'formula parser rejects a statement separator');
} catch (InvalidArgumentException $exception) {
    $check(true, 'formula parser rejects a statement separator');
}

try {
    formula_evaluate('note', ['note' => 12]);
    $check(true, 'formula parser still evaluates a plain variable');
} catch (Throwable $exception) {
    $check(false, 'formula parser still evaluates a plain variable');
}

$headers = (string) file_get_contents(app_path('config.php'));
$check(str_contains($headers, "header('X-Content-Type-Options: nosniff')"), 'responses send X-Content-Type-Options');
$check(str_contains($headers, 'admin_auth_generation'), 'administrator sessions are bound to a password generation');

$signup = (string) file_get_contents(app_path('Auth/creer_espace_etudiant.php'));
$check(str_contains($signup, 'student_birth_matches'), 'student signup requires the birth date stored on the dossier');
$check(function_exists('student_birth_matches')
    && student_birth_matches(['date_naissance' => '2002-04-03'], '2002-04-03')
    && !student_birth_matches(['date_naissance' => '2002-04-03'], '2002-04-04')
    && !student_birth_matches(['date_naissance' => ''], '2002-04-03'), 'birth-date comparison rejects a mismatch and an empty dossier date');

$legacy = (string) file_get_contents(app_path('Scolarite/inscription_old.php'));
$legacyPermission = strpos($legacy, "require_permission('students.edit')");
$legacyExit = $legacyPermission === false ? false : strpos($legacy, 'exit;', $legacyPermission);
$legacyUpdate = $legacyExit === false ? false : strpos($legacy, 'UPDATE etudiant SET', $legacyExit);
$check($legacyPermission !== false && $legacyExit !== false && $legacyUpdate !== false && $legacyExit < $legacyUpdate, 'legacy inscription redirects before it can update a student');

$placements = (string) file_get_contents(app_path('Stage/stage_placements.php'));
$check(!str_contains($placements, 'db()->quote('), 'stage placement filters do not concatenate quoted request values');

$quoted = pgsql_sql('SELECT 1 WHERE label = \'a"b"c\'');
$check(str_contains($quoted, 'a"b"c') && !str_contains($quoted, "a'b'c"), 'PostgreSQL rewriter leaves double quotes inside a single-quoted literal');

$inscription = (string) file_get_contents(app_path('Scolarite/inscription.php'));
$check(str_contains($inscription, 'verify_csrf()'), 'student registration POST checks CSRF');

auth_bucket_clear('id:' . $bucket);
auth_bucket_clear('ip:' . auth_client_address());

echo $failures === [] ? 'SECURITY CONTROLS PASSED' . PHP_EOL : 'SECURITY CONTROLS FAILED' . PHP_EOL;
exit($failures === [] ? 0 : 1);
