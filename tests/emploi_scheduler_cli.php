<?php
declare(strict_types=1);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/application/bootstrap.php';
require_once app_path('support/emploi_scheduler.php');

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
    echo ($ok ? 'ok  ' : 'FAIL ') . $label . PHP_EOL;
};

$config = emploi_smart_default_config();
$config['working_days'] = ['Lundi', 'Mardi', 'Mercredi'];
$config['max_group_hours_day'] = 10;
$config['max_teacher_hours_day'] = 10;

$resources = [
    ['id_ressource' => 1, 'nom' => 'Amphi 1', 'type_ressource' => 'amphi', 'lab_category' => '', 'capacite' => 100, 'active' => 1],
    ['id_ressource' => 2, 'nom' => 'Salle 1', 'type_ressource' => 'salle', 'lab_category' => '', 'capacite' => 30, 'active' => 1],
    ['id_ressource' => 3, 'nom' => 'Salle 2', 'type_ressource' => 'salle', 'lab_category' => '', 'capacite' => 30, 'active' => 1],
    ['id_ressource' => 4, 'nom' => 'Labo Inf 1', 'type_ressource' => 'labo', 'lab_category' => 'Informatique', 'capacite' => 20, 'active' => 1],
    ['id_ressource' => 5, 'nom' => 'Labo Infirm 1', 'type_ressource' => 'labo', 'lab_category' => 'Laboratoire infirmière', 'capacite' => 12, 'active' => 1],
];

// 1-2: two groups same teacher — must not overlap teacher
$composantes = [
    ['id_composante' => 1, 'id_mat' => 10, 'type_seance' => 'Cours', 'id_enseignant' => 1, 'id_groupe' => 101, 'duration_minutes' => 90, 'sessions_per_week' => 1, 'frequency' => 'weekly', 'students_count' => 50, 'preferred_resource_type' => 'amphi', 'lab_category' => '', 'code_ecue' => 'A1'],
    ['id_composante' => 2, 'id_mat' => 11, 'type_seance' => 'Cours', 'id_enseignant' => 1, 'id_groupe' => 102, 'duration_minutes' => 90, 'sessions_per_week' => 1, 'frequency' => 'weekly', 'students_count' => 50, 'preferred_resource_type' => 'amphi', 'lab_category' => '', 'code_ecue' => 'A2'],
];
$r = emploi_scheduler_generate($composantes, $resources, $config, []);
$check($r['scheduled_count'] === 2, 'two groups same teacher both scheduled');
$teacherOverlap = false;
if (count($r['scheduled']) === 2) {
    $a = $r['scheduled'][0];
    $b = $r['scheduled'][1];
    if ($a['jour'] === $b['jour'] && emploi_smart_overlaps(
        emploi_smart_minutes($a['heure_debut']),
        emploi_smart_minutes($a['heure_fin']),
        emploi_smart_minutes($b['heure_debut']),
        emploi_smart_minutes($b['heure_fin'])
    )) {
        $teacherOverlap = true;
    }
}
$check(!$teacherOverlap, 'same teacher never overlaps');

// 3: two groups needing same classroom type with only one salle of capacity — still ok with 2 salles
$composantes = [
    ['id_composante' => 3, 'id_mat' => 12, 'type_seance' => 'TD', 'id_enseignant' => 2, 'id_groupe' => 201, 'duration_minutes' => 90, 'sessions_per_week' => 1, 'frequency' => 'weekly', 'students_count' => 25, 'preferred_resource_type' => 'salle', 'lab_category' => '', 'code_ecue' => 'T1'],
    ['id_composante' => 4, 'id_mat' => 13, 'type_seance' => 'TD', 'id_enseignant' => 3, 'id_groupe' => 202, 'duration_minutes' => 90, 'sessions_per_week' => 1, 'frequency' => 'weekly', 'students_count' => 25, 'preferred_resource_type' => 'salle', 'lab_category' => '', 'code_ecue' => 'T2'],
];
$r = emploi_scheduler_generate($composantes, $resources, $config, []);
$check($r['scheduled_count'] === 2, 'two TD with two classrooms scheduled');
$roomOverlap = false;
foreach ($r['scheduled'] as $i => $a) {
    foreach ($r['scheduled'] as $j => $b) {
        if ($i >= $j) {
            continue;
        }
        if ((int) $a['id_ressource'] === (int) $b['id_ressource'] && $a['jour'] === $b['jour'] && emploi_smart_overlaps(
            emploi_smart_minutes($a['heure_debut']),
            emploi_smart_minutes($a['heure_fin']),
            emploi_smart_minutes($b['heure_debut']),
            emploi_smart_minutes($b['heure_fin'])
        )) {
            $roomOverlap = true;
        }
    }
}
$check(!$roomOverlap, 'resource exclusivity holds for TD rooms');

// 4-5: laboratory scarcity + capacity
$oneLab = [
    ['id_ressource' => 4, 'nom' => 'Labo Inf 1', 'type_ressource' => 'labo', 'lab_category' => 'Informatique', 'capacite' => 20, 'active' => 1],
];
$composantes = [
    ['id_composante' => 5, 'id_mat' => 14, 'type_seance' => 'TP', 'id_enseignant' => 4, 'id_groupe' => 301, 'duration_minutes' => 180, 'sessions_per_week' => 1, 'frequency' => 'weekly', 'students_count' => 18, 'preferred_resource_type' => 'labo', 'lab_category' => 'Informatique', 'code_ecue' => 'P1'],
    ['id_composante' => 6, 'id_mat' => 15, 'type_seance' => 'TP', 'id_enseignant' => 5, 'id_groupe' => 302, 'duration_minutes' => 180, 'sessions_per_week' => 1, 'frequency' => 'weekly', 'students_count' => 18, 'preferred_resource_type' => 'labo', 'lab_category' => 'Informatique', 'code_ecue' => 'P2'],
];
$r = emploi_scheduler_generate($composantes, $oneLab, $config, []);
$check($r['scheduled_count'] === 2 && $r['unscheduled_count'] === 0, 'two TP one lab different times');
$labOverlap = false;
if (count($r['scheduled']) === 2) {
    $a = $r['scheduled'][0];
    $b = $r['scheduled'][1];
    if ($a['jour'] === $b['jour'] && emploi_smart_overlaps(
        emploi_smart_minutes($a['heure_debut']),
        emploi_smart_minutes($a['heure_fin']),
        emploi_smart_minutes($b['heure_debut']),
        emploi_smart_minutes($b['heure_fin'])
    )) {
        $labOverlap = true;
    }
}
$check(!$labOverlap, 'one lab never hosts two groups at once');

$composantes = [
    ['id_composante' => 7, 'id_mat' => 16, 'type_seance' => 'TP', 'id_enseignant' => 6, 'id_groupe' => 303, 'duration_minutes' => 180, 'sessions_per_week' => 1, 'frequency' => 'weekly', 'students_count' => 25, 'preferred_resource_type' => 'labo', 'lab_category' => 'Informatique', 'code_ecue' => 'P3'],
];
$r = emploi_scheduler_generate($composantes, $oneLab, $config, []);
$check($r['unscheduled_count'] === 1 && str_contains((string) ($r['unscheduled'][0]['reason'] ?? ''), 'ressource'), 'insufficient lab capacity left unscheduled');

// 6: amphitheatre capacity
$tinyAmphi = [['id_ressource' => 1, 'nom' => 'Amphi tiny', 'type_ressource' => 'amphi', 'lab_category' => '', 'capacite' => 10, 'active' => 1]];
$composantes = [
    ['id_composante' => 8, 'id_mat' => 17, 'type_seance' => 'Cours', 'id_enseignant' => 7, 'id_groupe' => 401, 'duration_minutes' => 90, 'sessions_per_week' => 1, 'frequency' => 'weekly', 'students_count' => 80, 'preferred_resource_type' => 'amphi', 'lab_category' => '', 'code_ecue' => 'C1'],
];
$r = emploi_scheduler_generate($composantes, $tinyAmphi, $config, []);
$check($r['unscheduled_count'] === 1, 'amphitheatre capacity failure unscheduled');

// 7: TD/TP subgroups (different groups)
$composantes = [
    ['id_composante' => 9, 'id_mat' => 18, 'type_seance' => 'Cours', 'id_enseignant' => 8, 'id_groupe' => 501, 'duration_minutes' => 90, 'sessions_per_week' => 1, 'frequency' => 'weekly', 'students_count' => 50, 'preferred_resource_type' => 'amphi', 'lab_category' => '', 'code_ecue' => 'AN'],
    ['id_composante' => 10, 'id_mat' => 18, 'type_seance' => 'TD', 'id_enseignant' => 9, 'id_groupe' => 502, 'duration_minutes' => 90, 'sessions_per_week' => 1, 'frequency' => 'weekly', 'students_count' => 25, 'preferred_resource_type' => 'salle', 'lab_category' => '', 'code_ecue' => 'AN'],
    ['id_composante' => 11, 'id_mat' => 18, 'type_seance' => 'TP', 'id_enseignant' => 10, 'id_groupe' => 503, 'duration_minutes' => 180, 'sessions_per_week' => 1, 'frequency' => 'weekly', 'students_count' => 12, 'preferred_resource_type' => 'labo', 'lab_category' => 'Laboratoire infirmière', 'code_ecue' => 'AN'],
];
$r = emploi_scheduler_generate($composantes, $resources, $config, []);
$check($r['scheduled_count'] === 3, 'cours+td+tp subgroups scheduled');

// 8: STRICT rule — no shared multi-group co-booking (engine schedules separately)
$composantes = [
    ['id_composante' => 12, 'id_mat' => 19, 'type_seance' => 'Cours', 'id_enseignant' => 11, 'id_groupe' => 601, 'duration_minutes' => 90, 'sessions_per_week' => 1, 'frequency' => 'weekly', 'students_count' => 40, 'preferred_resource_type' => 'amphi', 'lab_category' => '', 'code_ecue' => 'SH'],
    ['id_composante' => 13, 'id_mat' => 19, 'type_seance' => 'Cours', 'id_enseignant' => 12, 'id_groupe' => 602, 'duration_minutes' => 90, 'sessions_per_week' => 1, 'frequency' => 'weekly', 'students_count' => 40, 'preferred_resource_type' => 'amphi', 'lab_category' => '', 'code_ecue' => 'SH'],
];
$r = emploi_scheduler_generate($composantes, [
    ['id_ressource' => 1, 'nom' => 'Amphi 1', 'type_ressource' => 'amphi', 'lab_category' => '', 'capacite' => 100, 'active' => 1],
], $config, []);
$sameSlotSameRoom = false;
if (count($r['scheduled']) === 2) {
    $a = $r['scheduled'][0];
    $b = $r['scheduled'][1];
    if ((int) $a['id_ressource'] === (int) $b['id_ressource'] && $a['jour'] === $b['jour'] && $a['heure_debut'] === $b['heure_debut']) {
        $sameSlotSameRoom = true;
    }
}
$check(!$sameSlotSameRoom, 'strict exclusivity: two groups never share amphi slot');

// 9: week A/B
$composantes = [
    ['id_composante' => 14, 'id_mat' => 20, 'type_seance' => 'TP', 'id_enseignant' => 13, 'id_groupe' => 701, 'duration_minutes' => 180, 'sessions_per_week' => 1, 'frequency' => 'fortnight_a', 'students_count' => 12, 'preferred_resource_type' => 'labo', 'lab_category' => 'Laboratoire infirmière', 'code_ecue' => 'QB'],
    ['id_composante' => 15, 'id_mat' => 20, 'type_seance' => 'TP', 'id_enseignant' => 13, 'id_groupe' => 702, 'duration_minutes' => 180, 'sessions_per_week' => 1, 'frequency' => 'fortnight_b', 'students_count' => 12, 'preferred_resource_type' => 'labo', 'lab_category' => 'Laboratoire infirmière', 'code_ecue' => 'QB'],
];
$r = emploi_scheduler_generate($composantes, $resources, $config, []);
$check($r['scheduled_count'] === 2, 'week A/B patterns schedule');

// 10: locked session preserved conflict blocks regen of overlapping
$locked = [[
    'id_composante' => 99,
    'id_groupe' => 801,
    'id_mat' => 21,
    'id_enseignant' => 20,
    'id_ressource' => 1,
    'type_seance' => 'Cours',
    'jour' => 'Lundi',
    'semaine' => 1,
    'week_pattern' => 'weekly',
    'heure_debut' => '08:15',
    'heure_fin' => '09:45',
    'locked' => 1,
]];
$composantes = [
    ['id_composante' => 16, 'id_mat' => 22, 'type_seance' => 'Cours', 'id_enseignant' => 20, 'id_groupe' => 802, 'duration_minutes' => 90, 'sessions_per_week' => 1, 'frequency' => 'weekly', 'students_count' => 40, 'preferred_resource_type' => 'amphi', 'lab_category' => '', 'code_ecue' => 'LK'],
];
$r = emploi_scheduler_generate($composantes, [
    ['id_ressource' => 1, 'nom' => 'Amphi 1', 'type_ressource' => 'amphi', 'lab_category' => '', 'capacite' => 100, 'active' => 1],
], $config, $locked);
$check($r['scheduled_count'] === 1, 'locked session allows alternate slot for other group');
$check((string) $r['scheduled'][0]['heure_debut'] !== '08:15' || (string) $r['scheduled'][0]['jour'] !== 'Lundi', 'new session avoids locked Lundi 08:15');

// 11: impossible constraints
$composantes = [
    ['id_composante' => 17, 'id_mat' => 23, 'type_seance' => 'TP', 'id_enseignant' => 30, 'id_groupe' => 901, 'duration_minutes' => 180, 'sessions_per_week' => 5, 'frequency' => 'weekly', 'students_count' => 12, 'preferred_resource_type' => 'labo', 'lab_category' => 'Informatique', 'code_ecue' => 'IMP'],
];
$r = emploi_scheduler_generate($composantes, $oneLab, array_merge($config, ['max_group_hours_day' => 3]), []);
$check($r['unscheduled_count'] >= 1, 'impossible set reports unscheduled with reasons');
$check(!empty($r['unscheduled'][0]['suggestions']), 'unscheduled includes suggestions');

// 12: conflict helper
$conflict = emploi_smart_session_conflict(
    ['jour' => 'Lundi', 'heure_debut' => '08:15', 'heure_fin' => '09:45', 'semaine' => 1, 'week_pattern' => 'weekly', 'id_enseignant' => 1, 'id_groupe' => 1, 'id_ressource' => 1],
    [['jour' => 'Lundi', 'heure_debut' => '08:15', 'heure_fin' => '09:45', 'semaine' => 1, 'week_pattern' => 'weekly', 'id_enseignant' => 2, 'id_groupe' => 2, 'id_ressource' => 1]]
);
$check($conflict !== null && str_contains($conflict, 'ressource'), 'manual conflict detects resource exclusivity');

$check(emploi_smart_overlaps(0, 90, 90, 180) === false, 'touching intervals do not overlap');
$check(emploi_smart_overlaps(0, 91, 90, 180) === true, 'partial overlap detected');

echo PHP_EOL . (count($failures) === 0 ? 'ALL PASS' : ('FAILURES: ' . implode('; ', $failures))) . PHP_EOL;
exit(count($failures) === 0 ? 0 : 1);
