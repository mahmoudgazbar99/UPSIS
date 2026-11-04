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

academic_policy_ensure();
$check(count(academic_period_codes()) >= 6, 'at least six periods');
$check(count(study_levels()) >= 3, 'at least three levels');
$check(abs(institution_fee_amount() - 9800.0) < 0.001, 'default fee stays 9800');
$plan = progression_plan('2', 10.0);
$check($plan['decision'] === 'admis' && $plan['next_level'] === '3' && $plan['statut'] === 'diplome', 'year 2 at 10 graduates to level 3');
$plan = progression_plan('1', 9.99);
$check($plan['decision'] === 'redouble' && $plan['next_level'] === '1', 'year 1 below 10 repeats');
$check(identity_matches('cin', '12345678') && !identity_matches('cin', '1234567'), 'cin 8 digits');
$check(identity_matches('passeport', 'AB12CD3') && !identity_matches('passeport', 'ABC'), 'passport 7 chars');
$check(institution_city() === 'Gabès' && institution_place_ar('2026/10/14') === 'قابس في، 2026/10/14', 'default place');

$pdo = db();
$programId = 0;
try {
    $programId = academic_add_program('TESTB', 'Programme test cinq ans', 'برنامج', 'Master test', 'ماجستير');
    foreach ([
        ['1', '1re', 'السنة الأولى', 1, false, 'S1,S2'],
        ['2', '2e', 'السنة الثانية', 2, false, 'S3,S4'],
        ['3', '3e', 'السنة الثالثة', 3, false, 'S5,S6'],
        ['4', '4e', 'السنة الرابعة', 4, false, 'S1,S2'],
        ['5', '5e', 'السنة الخامسة', 5, true, 'S3,S4'],
    ] as $level) {
        academic_add_level($programId, $level[0], $level[1], $level[2], $level[3], $level[4], $level[5]);
    }
    academic_publish_replace('progression_policy', $programId, [
        'pass_threshold' => '12.00',
        'graduate_status' => 'diplome',
    ]);
    academic_add_period('T1', 'Trimestre 1', 'الثلاثي الأول', 20, 'trimester');
    academic_publish_replace('fee_plan', $programId, [
        'label' => 'Frais test',
        'total_amount' => '1500.00',
        'currency_code' => 'EUR',
    ]);
    $check(count(study_levels($programId)) === 5, 'five levels on program B');
    $check(in_array('T1', academic_period_codes(), true), 'non-semester period T1');
    $periodId = (int) $pdo->query("SELECT id_period FROM academic_period WHERE code = 'T1'")->fetchColumn();
    academic_update_period($periodId, 'Premier trimestre', 'الثلاثي الأول المعدل', 21, 'trimester');
    $updatedPeriod = $pdo->query("SELECT label, label_ar, sequence FROM academic_period WHERE id_period = " . $periodId)->fetch();
    $check($updatedPeriod['label'] === 'Premier trimestre' && $updatedPeriod['label_ar'] === 'الثلاثي الأول المعدل' && (int) $updatedPeriod['sequence'] === 21, 'period edit updates French and Arabic labels');
    $levelId = (int) $pdo->query("SELECT id_level FROM study_level WHERE id_program = " . $programId . " AND code = '4'")->fetchColumn();
    academic_update_level($levelId, '4e modifié', 'السنة الرابعة المعدلة', 4, false, 'S1,S2');
    $updatedLevel = $pdo->query("SELECT label, label_ar FROM study_level WHERE id_level = " . $levelId)->fetch();
    $check($updatedLevel['label'] === '4e modifié' && $updatedLevel['label_ar'] === 'السنة الرابعة المعدلة', 'level edit updates French and Arabic labels');
    $high = progression_plan('4', 12.0, $programId);
    $check($high['decision'] === 'admis' && $high['next_level'] === '5' && $high['statut'] === 'diplome', 'program B threshold 12');
    $low = progression_plan('4', 11.5, $programId);
    $check($low['decision'] === 'redouble', 'program B rejects 11.5');
    $check(abs(institution_fee_amount($programId) - 1500.0) < 0.001, 'program fee 1500');
    $check(abs(institution_fee_amount() - 9800.0) < 0.001, 'institution fee unchanged');
    $default = progression_plan('2', 10.0);
    $check($default['decision'] === 'admis' && abs($default['threshold'] - 10.0) < 0.001, 'default promotion unchanged');
} catch (Throwable $exception) {
    $failures[] = 'fixture: ' . $exception->getMessage();
    echo 'FAIL fixture ' . $exception->getMessage() . PHP_EOL;
}
if (!empty($programId)) {
    $pdo->prepare('DELETE FROM study_level WHERE id_program = ?')->execute([$programId]);
    $pdo->prepare('DELETE FROM progression_policy WHERE id_program = ?')->execute([$programId]);
    $pdo->prepare('DELETE FROM fee_plan WHERE id_program = ?')->execute([$programId]);
    $pdo->prepare('DELETE FROM program WHERE id_program = ?')->execute([$programId]);
}
$pdo->exec("DELETE FROM academic_period WHERE code = 'T1'");

$left = (int) $pdo->query("SELECT COUNT(*) FROM program WHERE code = 'TESTB'")->fetchColumn();
$check($left === 0, 'test program rolled back');
echo $failures === [] ? "ALL PASSED\n" : ("FAILED " . count($failures) . "\n");
exit($failures === [] ? 0 : 1);
