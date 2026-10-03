<?php
declare(strict_types=1);
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

const DB_HOST = 'localhost';
const DB_NAME = 'ecole_sante_gabes';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . (getenv('DB_HOST') ?: DB_HOST) . ';dbname=' . (getenv('DB_NAME') ?: DB_NAME) . ';charset=utf8mb4',
            getenv('DB_USER') ?: DB_USER,
            getenv('DB_PASS') ?: DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ]
        );
        $pdo->setAttribute(PDO::MYSQL_ATTR_INIT_COMMAND, 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    return $pdo;
}

function stage_accounts_table_exists(): bool {
    $statement = db()->prepare(
        'SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $statement->execute(['stage_user']);
    return (bool) $statement->fetchColumn();
}

function logged_in(): bool { return !empty($_SESSION['user_role']); }
function is_admin(): bool { return ($_SESSION['user_role'] ?? '') === 'admin'; }
function is_teacher(): bool { return ($_SESSION['user_role'] ?? '') === 'enseignant'; }
function is_finance(): bool { return ($_SESSION['user_role'] ?? '') === 'finance'; }
function is_stage(): bool { return ($_SESSION['user_role'] ?? '') === 'stage'; }
function current_teacher_id(): int { return (int) ($_SESSION['teacher_id'] ?? 0); }
function current_stage_id(): int { return (int) ($_SESSION['stage_id'] ?? 0); }
function admin_password_hash(): string {
    static $hash;
    if ($hash === null) {
        $hash = (string) db()->query('SELECT password_hash FROM admin_user WHERE username = "admin" LIMIT 1')->fetchColumn();
    }
    return $hash;
}
function require_login(): void {
    if (!logged_in()) { header('Location: login.php'); exit; }
    if (is_stage()) {
        validate_stage_session();
        if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'stage_portal.php') {
            header('Location: stage_portal.php');
            exit;
        }
    }
}
function require_admin(): void { require_login(); if (!is_admin()) { http_response_code(403); exit('Accès réservé à l’administrateur.'); } }
function require_teacher_or_admin(): void { require_login(); if (!is_admin() && !is_teacher()) { http_response_code(403); exit('Rôle non autorisé.'); } }
function require_stage(): void {
    if (!logged_in()) { header('Location: login.php'); exit; }
    if (!is_stage()) { http_response_code(403); exit('Accès réservé au service des stages.'); }
    validate_stage_session();
}
function validate_stage_session(): void {
    if (!stage_accounts_table_exists()) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php?error=stage_setup');
        exit;
    }
    $statement = db()->prepare('SELECT auth_generation FROM stage_user WHERE id_stage = ? AND active = 1 LIMIT 1');
    $statement->execute([current_stage_id()]);
    $generation = $statement->fetchColumn();
    if ($generation === false || (int) $generation !== (int) ($_SESSION['stage_auth_generation'] ?? -1)) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php');
        exit;
    }
}
function require_finance(): void {
    require_login();
    if (!is_finance()) { http_response_code(403); exit('Accès réservé au service finance.'); }
    $statement = db()->prepare('SELECT auth_generation FROM finance_user WHERE id_finance = ? AND active = 1 LIMIT 1');
    $statement->execute([(int) ($_SESSION['finance_id'] ?? 0)]);
    $generation = $statement->fetchColumn();
    if ($generation === false || (int) $generation !== (int) ($_SESSION['finance_auth_generation'] ?? -1)) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php');
        exit;
    }
}
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function verify_csrf(): void {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(419);
        exit('Requête invalide. Veuillez actualiser la page.');
    }
}
function e(?string $value): string {
    $value = (string) $value;
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function arabic_year(string $level): string { return ['1' => 'السنة الأولى', '2' => 'السنة الثانية', '3' => 'السنة الثالثة'][$level] ?? $level; }
function level_label(string $level): string {
    return ['1' => '1er', '2' => '2ème', '3' => '3ème'][$level] ?? $level;
}
function calculated_note(array $mark): ?float {
    if ($mark['note_rattrapage'] !== null) return (float) $mark['note_rattrapage'];
    if ($mark['note_cours'] === null || $mark['note_td'] === null) {
        return $mark['note'] === null ? null : (float) $mark['note'];
    }
    $cc = (float) $mark['note_cours'];
    $exam = (float) $mark['note_td'];
    if ((float) ($mark['volume_tp'] ?? 0) > 0 && $mark['note_tp'] !== null) {
        return round($cc * 0.25 + $exam * 0.5 + (float) $mark['note_tp'] * 0.25, 2);
    }
    return round($cc * 0.3 + $exam * 0.7, 2);
}

function enforce_stage_route_isolation(): void {
    if (!is_stage()) return;
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['stage_portal.php', 'logout.php'], true)) return;
    require_login();
}
enforce_stage_route_isolation();

function assign_tronc_commun_groups(PDO $pdo, int $studentId): void {
    $courseQuery = $pdo->prepare(
        'SELECT g.id_groupe, COUNT(ge.id_etudiant) AS effectif
         FROM groupe g
         LEFT JOIN groupe_etudiant ge ON ge.id_groupe = g.id_groupe
         WHERE g.semestre = ? AND g.type_groupe = "Cours"
         GROUP BY g.id_groupe, g.numero
         ORDER BY g.numero'
    );
    $tdQuery = $pdo->prepare(
        'SELECT id_groupe FROM groupe
         WHERE id_groupe_parent = ? AND type_groupe = "TD"
         ORDER BY numero'
    );
    $attach = $pdo->prepare('INSERT INTO groupe_etudiant (id_groupe, id_etudiant) VALUES (?, ?)');
    foreach (['S1', 'S2', 'S3'] as $semester) {
        $courseQuery->execute([$semester]);
        $courses = $courseQuery->fetchAll();
        if (!$courses) {
            throw new RuntimeException('Aucun groupe Cours disponible pour ' . $semester . '.');
        }

        $minimumEnrollment = min(array_map(
            static fn (array $course): int => (int) $course['effectif'],
            $courses
        ));
        $leastPopulated = array_values(array_filter(
            $courses,
            static fn (array $course): bool => (int) $course['effectif'] === $minimumEnrollment
        ));
        $courseGroupId = (int) $leastPopulated[random_int(0, count($leastPopulated) - 1)]['id_groupe'];

        $tdQuery->execute([$courseGroupId]);
        $tdGroupIds = array_map('intval', $tdQuery->fetchAll(PDO::FETCH_COLUMN));
        if (count($tdGroupIds) !== 2) {
            throw new RuntimeException('Chaque groupe Cours doit avoir exactement deux groupes TD.');
        }

        $attach->execute([$courseGroupId, $studentId]);
        $attach->execute([$tdGroupIds[random_int(0, 1)], $studentId]);
    }
}

function assign_student_to_academic_groups(PDO $pdo, int $studentId, string $level): void {
    $semestersByLevel = [
        '1' => ['S1', 'S2'],
        '2' => ['S3', 'S4'],
        '3' => ['S5', 'S6'],
    ];
    $semesters = $semestersByLevel[$level] ?? null;
    if ($semesters === null) {
        throw new RuntimeException('Le niveau académique sélectionné est invalide.');
    }

    $courseQuery = $pdo->prepare(
        'SELECT g.id_groupe, COUNT(ge.id_etudiant) AS effectif
         FROM groupe g
         LEFT JOIN groupe_etudiant ge ON ge.id_groupe = g.id_groupe
         WHERE g.semestre = ? AND g.type_groupe = "Cours"
         GROUP BY g.id_groupe, g.numero
         ORDER BY g.numero'
    );
    $tdQuery = $pdo->prepare(
        'SELECT id_groupe FROM groupe
         WHERE id_groupe_parent = ? AND type_groupe = "TD"
         ORDER BY numero'
    );
    $attach = $pdo->prepare('INSERT INTO groupe_etudiant (id_groupe, id_etudiant) VALUES (?, ?)');

    foreach ($semesters as $semester) {
        $courseQuery->execute([$semester]);
        $courses = $courseQuery->fetchAll();
        if (!$courses) {
            throw new RuntimeException('Aucun groupe Cours disponible pour ' . $semester . '.');
        }

        $minimumEnrollment = min(array_map(
            static fn (array $course): int => (int) $course['effectif'],
            $courses
        ));
        $leastPopulated = array_values(array_filter(
            $courses,
            static fn (array $course): bool => (int) $course['effectif'] === $minimumEnrollment
        ));
        $courseGroupId = (int) $leastPopulated[random_int(0, count($leastPopulated) - 1)]['id_groupe'];

        $tdQuery->execute([$courseGroupId]);
        $tdGroupIds = array_map('intval', $tdQuery->fetchAll(PDO::FETCH_COLUMN));
        if (count($tdGroupIds) !== 2) {
            throw new RuntimeException('Chaque groupe Cours doit avoir exactement deux groupes TD.');
        }

        $attach->execute([$courseGroupId, $studentId]);
        $attach->execute([$tdGroupIds[random_int(0, 1)], $studentId]);
    }
}

const PRESENCE_SEMESTER_TOTAL_HOURS = 444.0;
const PRESENCE_SEMESTER_ABSENCE_RATIO = 0.10;
const PRESENCE_MATIERE_ABSENCE_LIMIT = 4;
const PRESENCE_DEFAULT_SESSION_HOURS = 1.5;
const PRESENCE_SEMESTER_CODE = 'S1';

function presence_semester_absence_limit_hours(): float
{
    return PRESENCE_SEMESTER_TOTAL_HOURS * PRESENCE_SEMESTER_ABSENCE_RATIO;
}

function presence_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS presence_etudiant (
          id_presence INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
          id_groupe INT(10) UNSIGNED NOT NULL,
          id_mat INT(10) UNSIGNED DEFAULT NULL,
          id_etudiant INT(10) UNSIGNED NOT NULL,
          date_seance DATE NOT NULL,
          statut ENUM('present', 'absent') NOT NULL,
          duree_heures DECIMAL(5,2) NOT NULL DEFAULT 1.50,
          id_enseignant INT(10) UNSIGNED DEFAULT NULL,
          recorded_by_role ENUM('admin', 'enseignant') NOT NULL DEFAULT 'admin',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id_presence),
          UNIQUE KEY uq_presence_seance (id_groupe, id_mat, id_etudiant, date_seance),
          KEY idx_presence_groupe_date (id_groupe, date_seance),
          KEY idx_presence_etudiant (id_etudiant),
          KEY idx_presence_matiere (id_mat)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    try {
        $hasMat = $pdo->query('SHOW COLUMNS FROM presence_etudiant LIKE "id_mat"')->fetch();
        if (!$hasMat) {
            $pdo->exec('ALTER TABLE presence_etudiant ADD COLUMN id_mat INT(10) UNSIGNED DEFAULT NULL AFTER id_groupe');
            $pdo->exec('ALTER TABLE presence_etudiant DROP INDEX uq_presence_seance');
            $pdo->exec('ALTER TABLE presence_etudiant ADD UNIQUE KEY uq_presence_seance (id_groupe, id_mat, id_etudiant, date_seance)');
            $pdo->exec('ALTER TABLE presence_etudiant ADD KEY idx_presence_matiere (id_mat)');
        }
        $hasHours = $pdo->query('SHOW COLUMNS FROM presence_etudiant LIKE "duree_heures"')->fetch();
        if (!$hasHours) {
            $pdo->exec('ALTER TABLE presence_etudiant ADD COLUMN duree_heures DECIMAL(5,2) NOT NULL DEFAULT 1.50 AFTER statut');
        }
    } catch (Throwable $ignored) {
        /* Schema already aligned. */
    }
}

function presence_resolve_session_hours(PDO $pdo, int $groupId, int $matId): float
{
    $query = $pdo->prepare(
        'SELECT AVG(TIMESTAMPDIFF(MINUTE, heure_debut, heure_fin)) / 60
         FROM emploi_temps
         WHERE id_groupe = ? AND id_mat = ?'
    );
    $query->execute([$groupId, $matId]);
    $average = $query->fetchColumn();
    if ($average !== false && $average !== null && (float) $average > 0) {
        return round((float) $average, 2);
    }
    return PRESENCE_DEFAULT_SESSION_HOURS;
}

function presence_group_label(array $group): string
{
    if (($group['type_groupe'] ?? '') === 'TD') {
        $tdNumber = (int) ($group['td_numero'] ?? (((int) $group['numero'] - 1) % 2) + 1);
        $courseNumber = (string) ($group['parent_numero'] ?? '—');
        return $group['semestre'] . ' · Cours ' . $courseNumber . ' · TD ' . $tdNumber;
    }
    return $group['semestre'] . ' · Cours ' . (string) ($group['numero'] ?? '—');
}

function elimination_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS elimination_derogation (
          id_derogation INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
          scope ENUM('matiere', 'semestre') NOT NULL,
          id_etudiant INT(10) UNSIGNED NOT NULL,
          id_mat INT(10) UNSIGNED DEFAULT NULL,
          semestre VARCHAR(8) DEFAULT NULL,
          reason VARCHAR(500) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_derogation),
          KEY idx_elim_student (id_etudiant, scope)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function elimination_override_map(PDO $pdo): array
{
    elimination_ensure_table($pdo);
    $map = [];
    foreach ($pdo->query('SELECT scope, id_etudiant, id_mat, semestre FROM elimination_derogation') as $row) {
        if ($row['scope'] === 'matiere') {
            $map['matiere:' . (int) $row['id_etudiant'] . ':' . (int) $row['id_mat']] = true;
        } else {
            $map['semestre:' . (int) $row['id_etudiant'] . ':' . (string) $row['semestre']] = true;
        }
    }
    return $map;
}

function presence_elimination_alerts(PDO $pdo): array
{
    presence_ensure_table($pdo);
    $overrides = elimination_override_map($pdo);
    $limitHours = presence_semester_absence_limit_hours();
    $matiereLimit = PRESENCE_MATIERE_ABSENCE_LIMIT;

    $matiereQuery = $pdo->prepare(
        'SELECT e.id_etudiant, e.nom, e.prenom, e.cin_passeport, e.num_inscription, e.niveau,
                m.id_mat, m.code_ecue, m.description_ecue, m.semestre,
                COUNT(*) AS absences,
                ROUND(SUM(p.duree_heures), 2) AS heures_absentes
         FROM presence_etudiant p
         JOIN etudiant e ON e.id_etudiant = p.id_etudiant
         JOIN matiere m ON m.id_mat = p.id_mat
         WHERE p.statut = "absent" AND p.id_mat IS NOT NULL
         GROUP BY e.id_etudiant, e.nom, e.prenom, e.cin_passeport, e.num_inscription, e.niveau,
                  m.id_mat, m.code_ecue, m.description_ecue, m.semestre
         HAVING COUNT(*) >= ?
         ORDER BY absences DESC, e.nom, e.prenom, m.code_ecue'
    );
    $matiereQuery->execute([$matiereLimit]);
    $matiereRows = $matiereQuery->fetchAll();

    $semestreQuery = $pdo->prepare(
        'SELECT e.id_etudiant, e.nom, e.prenom, e.cin_passeport, e.num_inscription, e.niveau,
                COUNT(*) AS absences,
                ROUND(SUM(p.duree_heures), 2) AS heures_absentes
         FROM presence_etudiant p
         JOIN etudiant e ON e.id_etudiant = p.id_etudiant
         JOIN matiere m ON m.id_mat = p.id_mat
         WHERE p.statut = "absent" AND m.semestre = ?
         GROUP BY e.id_etudiant, e.nom, e.prenom, e.cin_passeport, e.num_inscription, e.niveau
         HAVING SUM(p.duree_heures) >= ?
         ORDER BY heures_absentes DESC, e.nom, e.prenom'
    );
    $semestreQuery->execute([PRESENCE_SEMESTER_CODE, $limitHours]);
    $semestreRows = $semestreQuery->fetchAll();
    $matiereRows = array_values(array_filter(
        $matiereRows,
        static fn (array $row): bool => !isset($overrides['matiere:' . (int) $row['id_etudiant'] . ':' . (int) $row['id_mat']])
    ));
    $semestreRows = array_values(array_filter(
        $semestreRows,
        static fn (array $row): bool => !isset($overrides['semestre:' . (int) $row['id_etudiant'] . ':' . PRESENCE_SEMESTER_CODE])
    ));

    $studentIds = [];
    foreach ($matiereRows as $row) {
        $studentIds[(int) $row['id_etudiant']] = true;
    }
    foreach ($semestreRows as $row) {
        $studentIds[(int) $row['id_etudiant']] = true;
    }

    return [
        'matiere' => $matiereRows,
        'semestre' => $semestreRows,
        'counts' => [
            'matiere' => count($matiereRows),
            'semestre' => count($semestreRows),
            'total' => count($studentIds),
        ],
        'limits' => [
            'matiere_absences' => $matiereLimit,
            'semestre_hours' => $limitHours,
            'semestre_total_hours' => PRESENCE_SEMESTER_TOTAL_HOURS,
            'semestre_ratio' => PRESENCE_SEMESTER_ABSENCE_RATIO,
            'semestre_code' => PRESENCE_SEMESTER_CODE,
            'session_hours' => PRESENCE_DEFAULT_SESSION_HOURS,
        ],
    ];
}

function audit_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS audit_log (
          id_audit INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
          actor_role VARCHAR(20) NOT NULL,
          actor_id INT(10) UNSIGNED DEFAULT NULL,
          actor_label VARCHAR(160) NOT NULL,
          category ENUM('note', 'paiement', 'presence', 'compte') NOT NULL,
          action_name VARCHAR(40) NOT NULL,
          summary VARCHAR(500) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_audit),
          KEY idx_audit_category_date (category, created_at),
          KEY idx_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_teacher_active(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    if (!$pdo->query("SHOW COLUMNS FROM enseignant LIKE 'active'")->fetch()) {
        $pdo->exec('ALTER TABLE enseignant ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1');
    }
}

function audit_record(string $category, string $action, string $summary): void
{
    if (!in_array($category, ['note', 'paiement', 'presence', 'compte'], true)) {
        return;
    }
    try {
        $pdo = db();
        audit_ensure_table($pdo);
        $role = (string) ($_SESSION['user_role'] ?? 'inconnu');
        $actorId = null;
        $label = 'Inconnu';
        if ($role === 'admin') {
            $label = 'Administrateur';
        } elseif ($role === 'enseignant') {
            $actorId = current_teacher_id();
            $query = $pdo->prepare('SELECT nom, prenom FROM enseignant WHERE id_enseignant = ? LIMIT 1');
            $query->execute([$actorId]);
            $teacher = $query->fetch();
            $label = trim((string) (($teacher['prenom'] ?? '') . ' ' . ($teacher['nom'] ?? ''))) ?: 'Enseignant';
        } elseif ($role === 'finance') {
            $actorId = (int) ($_SESSION['finance_id'] ?? 0);
            $query = $pdo->prepare('SELECT username FROM finance_user WHERE id_finance = ? LIMIT 1');
            $query->execute([$actorId]);
            $label = (string) ($query->fetchColumn() ?: 'Service finance');
        }
        $statement = $pdo->prepare(
            'INSERT INTO audit_log (actor_role, actor_id, actor_label, category, action_name, summary)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $role,
            $actorId,
            mb_substr($label, 0, 160),
            $category,
            mb_substr($action, 0, 40),
            mb_substr($summary, 0, 500),
        ]);
    } catch (Throwable $ignored) {
        /* A journal failure must not block the original action. */
    }
}
