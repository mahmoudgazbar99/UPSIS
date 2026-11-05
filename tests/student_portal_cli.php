<?php
declare(strict_types=1);
$_SERVER['SCRIPT_NAME'] = '/dashboard.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/application/bootstrap.php';
require dirname(__DIR__) . '/application/support/student_portal.php';

$pdo = db();
student_portal_ensure($pdo);
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name IN ('student_group_request', 'student_appointment', 'student_complaint')")->fetchAll(PDO::FETCH_COLUMN);
echo 'tables ' . implode(',', $tables) . PHP_EOL;
$id = (int) $pdo->query('SELECT id_etudiant FROM etudiant ORDER BY id_etudiant LIMIT 1')->fetchColumn();
echo 'student ' . $id . PHP_EOL;
if ($id > 0) {
    $plan = student_portal_plan($pdo, $id);
    $absences = student_portal_absences($pdo, $id);
    $group = student_portal_current_group($pdo, $id);
    $contacts = student_portal_contacts($pdo, $id);
    $hours = 0.0;
    foreach ($absences as $row) {
        $hours += (float) $row['duree_heures'];
    }
    echo 'plan ' . count($plan) . ' absences ' . count($absences) . ' hours ' . $hours . ' contacts ' . count($contacts) . PHP_EOL;
    echo 'group ' . ($group['id_groupe'] ?? 'none') . PHP_EOL;
    if ($group) {
        $spec = (int) $pdo->query('SELECT id_spec FROM etudiant WHERE id_etudiant = ' . $id)->fetchColumn();
        $open = student_portal_target_groups($pdo, $id, $spec, (int) $group['id_groupe'], true);
        $swap = student_portal_target_groups($pdo, $id, $spec, (int) $group['id_groupe'], false);
        $people = student_portal_swap_students($pdo, array_column($swap, 'id_groupe'), $spec, $id);
        echo 'open ' . count($open) . ' swap ' . count($swap) . ' people ' . count($people) . PHP_EOL;
    }
}
echo "ok\n";
