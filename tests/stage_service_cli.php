<?php
declare(strict_types=1);
$_SERVER['SCRIPT_NAME'] = '/stage_bureau.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/application/bootstrap.php';
require dirname(__DIR__) . '/application/support/stage_service.php';

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
    echo ($ok ? 'ok  ' : 'FAIL ') . $label . PHP_EOL;
};

stage_service_ensure();
$types = stage_service_types();
$codes = array_column($types, 'code');
$check(in_array('obligatoire', $codes, true) && in_array('ete', $codes, true), 'stage types come from stage_type');
$check(count(stage_service_doc_categories()) >= 8, 'document categories come from stage_modele_categorie');
$programs = academic_programs();
$check($programs !== [], 'programs come from program');
$specs = stage_service_specialites();
$check($specs !== [], 'specialties come from specialite');
$years = stage_service_years();
$check($years !== [], 'academic years come from academic_year');
$stats = stage_service_dashboard('', 0, 0);
foreach (['stages', 'obligatoires', 'ete', 'en_cours', 'termines', 'demandes', 'etudiants', 'etablissements', 'conventions', 'documents'] as $key) {
    $check(array_key_exists($key, $stats), 'dashboard key ' . $key);
}
$students = stage_service_students(0, '', '', 1, 5);
$check(array_key_exists('rows', $students) && array_key_exists('total', $students), 'student search reads etudiant');
try {
    stage_service_store_pdf(['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '']);
    $check(false, 'pdf upload rejects a missing file');
} catch (InvalidArgumentException $exception) {
    $check(true, 'pdf upload rejects a missing file');
}

if ($failures) {
    fwrite(STDERR, count($failures) . " stage service check(s) failed\n");
    exit(1);
}
echo "STAGE SERVICE CHECKS PASSED\n";
