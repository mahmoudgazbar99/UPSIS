<?php
declare(strict_types=1);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/application/bootstrap.php';
access_ensure();
$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
    echo ($ok ? 'ok  ' : 'FAIL ') . $label . PHP_EOL;
};
$check(stage_status_allowed('brouillon', 'en_attente') && !stage_status_allowed('termine', 'en_cours'), 'status transitions');
$_SESSION['user_role'] = 'scolarite';
$check(role_can('students.view') && role_can('documents.issue') && !role_can('stage.place'), 'scolarite permissions');
$_SESSION['user_role'] = 'stage';
$_SESSION['stage_duty'] = 'superviseur';
$check(role_can('stage.evaluate') && !role_can('stage.hosts'), 'supervisor cannot manage hosts');
$_SESSION['stage_duty'] = 'officer';
$check(role_can('stage.hosts') && role_can('stage.place'), 'officer can manage placements');
$_SESSION['user_role'] = 'admin';
$check(role_can('students.export'), 'admin keeps every permission');

$username = 'scol_test_' . bin2hex(random_bytes(3));
$hash = password_hash('MotDePasse9', PASSWORD_DEFAULT);
db()->prepare('INSERT INTO scolarite_user (username, nom, password) VALUES (?, ?, ?)')->execute([$username, 'Test Scolarité', $hash]);
$stored = db()->prepare('SELECT password FROM scolarite_user WHERE username = ?');
$stored->execute([$username]);
$saved = (string) $stored->fetchColumn();
$check(password_verify('MotDePasse9', $saved) && !str_contains($saved, 'MotDePasse9'), 'scolarite password is hashed');
db()->prepare('DELETE FROM scolarite_user WHERE username = ?')->execute([$username]);

$studentId = (int) db()->query('SELECT id_etudiant FROM etudiant WHERE statut = "actif" LIMIT 1')->fetchColumn();
$org = db()->prepare('INSERT INTO stage_organisme (nom, capacite, active) VALUES ("Organisme test CLI", 1, 1)');
$org->execute();
$orgId = (int) db()->lastInsertId();
$okPlacement = false;
try {
    stage_assert_placement($studentId, $orgId, '2026-11-01', '2026-10-01');
} catch (InvalidArgumentException $exception) {
    $okPlacement = str_contains($exception->getMessage(), 'dates');
}
$check($okPlacement, 'reversed dates are rejected');
db()->prepare(
    'INSERT INTO stage_placement (id_etudiant, id_organisme, date_debut, date_fin, statut, created_by) VALUES (?, ?, "2026-11-01", "2026-11-20", "en_cours", 0)'
)->execute([$studentId, $orgId]);
$blocked = false;
try {
    stage_assert_placement($studentId, $orgId, '2026-11-05', '2026-11-12');
} catch (InvalidArgumentException $exception) {
    $blocked = true;
}
$check($blocked, 'overlapping placement is rejected');
db()->prepare('DELETE FROM stage_placement WHERE id_organisme = ?')->execute([$orgId]);
db()->prepare('DELETE FROM stage_organisme WHERE id_organisme = ?')->execute([$orgId]);
$left = (int) db()->query('SELECT COUNT(*) FROM stage_organisme WHERE nom = "Organisme test CLI"')->fetchColumn();
$check($left === 0, 'test host removed');
echo $failures === [] ? "ALL PASSED\n" : 'FAILED ' . count($failures) . "\n";
exit($failures === [] ? 0 : 1);
