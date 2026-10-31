<?php
declare(strict_types=1);

if (defined('APP_CONFIG_LOADED')) {
    return;
}
define('APP_CONFIG_LOADED', true);

function app_is_student_request(): bool
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === 'account_status.php') {
        return ($_GET['espace'] ?? '') === 'etudiant';
    }
    if (in_array($script, ['stage_dossier.php', 'stage_document.php', 'emploi_pdf.php', 'cours_fichier.php'], true)
        && ($_GET['espace'] ?? '') === 'etudiant'
    ) {
        return true;
    }
    $studentPages = [
        'dashboard.php',
        'connexion_etudiant.php',
        'creer_espace_etudiant.php',
        'etudiant_inbox.php',
    ];
    if (in_array($script, $studentPages, true)) {
        return true;
    }
    if ($script !== 'logout.php') {
        return false;
    }
    if (($_GET['espace'] ?? '') === 'etudiant') {
        return true;
    }
    $refererPath = (string) (parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?: '');
    return in_array(basename($refererPath), $studentPages, true);
}

if (app_is_student_request()) {
    session_name('ESG_STUDENT');
}

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
app_send_security_headers();
if (!app_is_student_request() && ($_SESSION['user_role'] ?? '') === 'etudiant') {
    unset($_SESSION['user_role'], $_SESSION['id_etudiant']);
}

const DB_HOST = '127.0.0.1';
const DB_PORT = '5433';
const DB_NAME = 'ecole_sante_gabes';
const DB_USER = 'myapp_user';
const DB_PASS = 'myapp_password';

require_once __DIR__ . '/pgsql.php';

function db_connect(): AppPdo
{
    $host = getenv('DB_HOST') ?: DB_HOST;
    $port = getenv('DB_PORT') ?: DB_PORT;
    $pdo = new AppPdo(
        'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . (getenv('DB_NAME') ?: DB_NAME),
        getenv('DB_USER') ?: DB_USER,
        getenv('DB_PASS') ?: DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET client_encoding TO 'UTF8'");
    $pdo->exec("SET TIME ZONE 'UTC'");
    return $pdo;
}

function db(): AppPdo
{
    static $pdo;
    if (!$pdo) {
        $pdo = db_connect();
    }
    return $pdo;
}

function student_normalize_cin(string $cin): string
{
    return preg_replace('/\s+/u', '', trim($cin)) ?? '';
}

function student_find_by_cin(string $cin): ?array
{
    $cin = student_normalize_cin($cin);
    if ($cin === '') {
        return null;
    }
    $query = db()->prepare(
        'SELECT * FROM etudiant
         WHERE TRIM(REPLACE(cin_passeport, " ", "")) = ?
         LIMIT 1'
    );
    $query->execute([$cin]);
    $row = $query->fetch();
    return $row ?: null;
}

function student_birth_matches(array $student, string $birth): bool
{
    $stored = substr((string) ($student['date_naissance'] ?? ''), 0, 10);
    return $stored !== ''
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) === 1
        && hash_equals($stored, $birth);
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
function app_send_security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Permitted-Cross-Domain-Policies: none');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

function is_admin(): bool { return ($_SESSION['user_role'] ?? '') === 'admin'; }
function is_teacher(): bool { return ($_SESSION['user_role'] ?? '') === 'enseignant'; }
function is_finance(): bool { return ($_SESSION['user_role'] ?? '') === 'finance'; }
function is_stage(): bool { return ($_SESSION['user_role'] ?? '') === 'stage'; }
function is_student(): bool { return ($_SESSION['user_role'] ?? '') === 'etudiant'; }
function account_is_active_value(mixed $value): bool
{
    return in_array($value, [true, 1, '1', 't', 'true'], true);
}
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
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!stage_script_allowed($script)) {
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
    $statement = db()->prepare('SELECT active, auth_generation FROM stage_user WHERE id_stage = ? LIMIT 1');
    $statement->execute([current_stage_id()]);
    $account = $statement->fetch();
    if (!$account || !account_is_active_value($account['active'] ?? null)) {
        reject_suspended_account();
    }
    if ((int) $account['auth_generation'] !== (int) ($_SESSION['stage_auth_generation'] ?? -1)) {
        $loginPage = app_logout_current(false);
        header('Location: ' . $loginPage);
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
function app_logout_current(?bool $studentSpace = null): string
{
    $next = ($studentSpace ?? app_is_student_request()) ? 'connexion_etudiant.php' : 'login.php';
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }
    return $next;
}

function reject_suspended_account(): never
{
    $studentSpace = is_student();
    $loginPage = app_logout_current($studentSpace);
    header('Location: ' . $loginPage . '?error=suspended');
    exit;
}

function validate_current_account_status(): void
{
    $status = current_account_session_status();
    if ($status === 'anonymous' || $status === 'active') {
        return;
    }
    if ($status === 'suspended') {
        reject_suspended_account();
    }
    $role = (string) $_SESSION['user_role'];
    $loginPage = app_logout_current($role === 'etudiant');
    header('Location: ' . $loginPage);
    exit;
}

function current_account_session_status(): string
{
    if (!logged_in()) {
        return 'anonymous';
    }
    if (is_admin()) {
        $generation = admin_auth_generation();
        if ((int) ($_SESSION['admin_auth_generation'] ?? -1) !== $generation) {
            return 'revoked';
        }
        return 'active';
    }

    $role = (string) $_SESSION['user_role'];
    $accountId = 0;
    $generationKey = '';
    $query = '';

    if (is_teacher()) {
        ensure_teacher_active(db());
        $accountId = current_teacher_id();
        $generationKey = 'teacher_auth_generation';
        $query = 'SELECT active, auth_generation FROM enseignant WHERE id_enseignant = ? LIMIT 1';
    } elseif (is_stage()) {
        $accountId = current_stage_id();
        $generationKey = 'stage_auth_generation';
        $query = 'SELECT active, auth_generation FROM stage_user WHERE id_stage = ? LIMIT 1';
    } elseif (is_finance()) {
        $accountId = (int) ($_SESSION['finance_id'] ?? 0);
        $generationKey = 'finance_auth_generation';
        $query = 'SELECT active, auth_generation FROM finance_user WHERE id_finance = ? LIMIT 1';
    } elseif (is_scolarite()) {
        $accountId = current_scolarite_id();
        $generationKey = 'scolarite_auth_generation';
        $query = 'SELECT active, auth_generation FROM scolarite_user WHERE id_scolarite = ? LIMIT 1';
    } elseif (is_student()) {
        ensure_etudiant_compte_table(db());
        $accountId = (int) ($_SESSION['id_etudiant'] ?? 0);
        $generationKey = 'student_auth_generation';
        $query = 'SELECT active, auth_generation FROM etudiant_compte WHERE id_etudiant = ? LIMIT 1';
    } else {
        return 'revoked';
    }

    $statement = db()->prepare($query);
    $statement->execute([$accountId]);
    $account = $statement->fetch();
    if (!$account || !account_is_active_value($account['active'] ?? null)) {
        return 'suspended';
    }

    if ($generationKey !== '') {
        $generation = (int) ($account['auth_generation'] ?? 0);
        if (!array_key_exists($generationKey, $_SESSION)) {
            $_SESSION[$generationKey] = $generation;
        } elseif ($generation !== (int) $_SESSION[$generationKey]) {
            return 'revoked';
        }
    }

    return 'active';
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

function auth_client_address(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip !== '' ? $ip : 'local';
}

function auth_throttle_path(string $bucket): string
{
    $dir = storage_path('cache/auth-throttle');
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Le contrôle des tentatives de connexion est indisponible.');
    }
    return $dir . DIRECTORY_SEPARATOR . hash('sha256', $bucket) . '.json';
}

/** @return array{fails:int,window:int,locked_until:int} */
function auth_throttle_read(string $bucket): array
{
    $path = auth_throttle_path($bucket);
    if (!is_file($path)) {
        return ['fails' => 0, 'window' => 0, 'locked_until' => 0];
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return ['fails' => 0, 'window' => 0, 'locked_until' => 0];
    }
    return [
        'fails' => (int) ($data['fails'] ?? 0),
        'window' => (int) ($data['window'] ?? 0),
        'locked_until' => (int) ($data['locked_until'] ?? 0),
    ];
}

function auth_throttle_write(string $bucket, int $fails, int $window, int $lockedUntil): void
{
    file_put_contents(auth_throttle_path($bucket), json_encode([
        'fails' => $fails,
        'window' => $window,
        'locked_until' => $lockedUntil,
    ]), LOCK_EX);
}

function auth_bucket_locked(string $bucket, int $limit): bool
{
    $data = auth_throttle_read($bucket);
    if ($data['locked_until'] > time()) {
        return true;
    }
    if ($data['window'] > 0 && $data['window'] < time() - 900) {
        return false;
    }
    return $data['fails'] >= $limit;
}

function auth_bucket_hit(string $bucket, int $limit): void
{
    $data = auth_throttle_read($bucket);
    $window = $data['window'];
    $fails = $data['fails'];
    if ($window < time() - 900) {
        $window = time();
        $fails = 0;
    }
    $fails++;
    $lockedUntil = $fails >= $limit ? time() + 900 : 0;
    auth_throttle_write($bucket, $fails, $window, $lockedUntil);
}

function auth_bucket_clear(string $bucket): void
{
    $path = auth_throttle_path($bucket);
    if (is_file($path)) {
        unlink($path);
    }
}

function auth_rate_limited(string $identity): bool
{
    return auth_bucket_locked('id:' . $identity, 8) || auth_bucket_locked('ip:' . auth_client_address(), 60);
}

function auth_rate_fail(string $identity): void
{
    auth_bucket_hit('id:' . $identity, 8);
    auth_bucket_hit('ip:' . auth_client_address(), 60);
}

function auth_rate_clear(string $identity): void
{
    auth_bucket_clear('id:' . $identity);
}

function ensure_admin_auth_generation(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    if (!$pdo->query("SHOW COLUMNS FROM admin_user LIKE 'auth_generation'")->fetch()) {
        $pdo->exec('ALTER TABLE admin_user ADD COLUMN auth_generation INT UNSIGNED NOT NULL DEFAULT 1');
    }
    $ready = true;
}

function admin_auth_generation(): int
{
    ensure_admin_auth_generation(db());
    $value = db()->query('SELECT auth_generation FROM admin_user WHERE username = "admin" LIMIT 1')->fetchColumn();
    return $value === false ? 0 : (int) $value;
}
function e(?string $value): string {
    $value = (string) $value;
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function arabic_year(string $level): string { return study_level_label_ar($level); }
function level_label(string $level): string {
    return study_level_label($level);
}
function calculated_note(array $mark): ?float {
    return institution_subject_average($mark);
}

function app_public_entry(): bool
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return in_array($script, ['accueil.php', 'login.php', 'brand_logo.php'], true);
}

function enforce_stage_route_isolation(): void {
    if (!is_stage() || app_public_entry()) return;
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === 'account_status.php') return;
    if (stage_script_allowed($script)) {
        validate_stage_session();
        return;
    }
    require_login();
}
require_once __DIR__ . '/support/access_roles.php';
enforce_stage_route_isolation();

function enforce_student_route_isolation(): void {
    if (!is_student() || app_public_entry()) {
        return;
    }
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === 'account_status.php' && ($_GET['espace'] ?? '') === 'etudiant') {
        return;
    }
    if (in_array($script, ['dashboard.php', 'logout.php', 'etudiant_inbox.php', 'stage_dossier.php', 'stage_document.php', 'emploi_pdf.php', 'cours_fichier.php'], true)) {
        return;
    }
    header('Location: dashboard.php');
    exit;
}
enforce_student_route_isolation();

function db_table_exists(PDO $pdo, string $table): bool
{
    $query = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $query->execute([$table]);
    return (bool) $query->fetchColumn();
}

function attestation_ensure_tables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    if (!db_table_exists($pdo, 'system_settings')) {
        $pdo->exec(
            "CREATE TABLE system_settings (
              id_setting INT UNSIGNED NOT NULL AUTO_INCREMENT,
              setting_key VARCHAR(80) NOT NULL,
              setting_value VARCHAR(255) NOT NULL,
              PRIMARY KEY (id_setting),
              UNIQUE KEY uq_system_settings_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
    if (!db_table_exists($pdo, 'demande_attestation')) {
        $pdo->exec(
            "CREATE TABLE demande_attestation (
              id_demande INT UNSIGNED NOT NULL AUTO_INCREMENT,
              id_etudiant INT UNSIGNED NOT NULL,
              cin VARCHAR(40) NOT NULL,
              motif VARCHAR(500) NOT NULL,
              statut ENUM('en_attente', 'acceptee', 'refusee') NOT NULL DEFAULT 'en_attente',
              date_demande TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              date_acceptation TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (id_demande),
              KEY idx_demande_statut (statut, date_demande),
              KEY idx_demande_etudiant (id_etudiant)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)'
    );
    $insert->execute(['attestation_feature_active', '1']);
    student_notifications_ensure_tables($pdo);
    if (db_table_exists($pdo, 'demande_attestation')
        && !$pdo->query("SHOW COLUMNS FROM demande_attestation LIKE 'date_acceptation'")->fetch()
    ) {
        $pdo->exec(
            'ALTER TABLE demande_attestation
             ADD COLUMN date_acceptation TIMESTAMP NULL DEFAULT NULL AFTER date_demande'
        );
    }
    if (db_table_exists($pdo, 'demande_attestation')) {
        try {
            $pdo->exec(
                "CREATE UNIQUE INDEX IF NOT EXISTS uq_demande_etudiant_attente
                 ON demande_attestation (id_etudiant) WHERE statut = 'en_attente'"
            );
        } catch (Throwable $exception) {
            error_log('attestation pending unique index: ' . $exception->getMessage());
        }
    }
}

function attestation_feature_active(PDO $pdo): bool
{
    attestation_ensure_tables($pdo);
    $query = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
    $query->execute(['attestation_feature_active']);
    return (string) $query->fetchColumn() === '1';
}

function attestation_pending_count(PDO $pdo): int
{
    attestation_ensure_tables($pdo);
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM demande_attestation WHERE statut = 'en_attente'"
    )->fetchColumn();
}

function student_notifications_ensure_tables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    if (!db_table_exists($pdo, 'etudiant_notifications')) {
        $pdo->exec(
            "CREATE TABLE etudiant_notifications (
              id_notif INT UNSIGNED NOT NULL AUTO_INCREMENT,
              id_etudiant INT UNSIGNED NOT NULL,
              sujet VARCHAR(180) NOT NULL,
              message_body TEXT NOT NULL,
              lien VARCHAR(255) NULL DEFAULT NULL,
              is_read TINYINT(1) NOT NULL DEFAULT 0,
              date_creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id_notif),
              KEY idx_etudiant_notif_unread (id_etudiant, is_read, date_creation),
              CONSTRAINT fk_etudiant_notifications FOREIGN KEY (id_etudiant)
                REFERENCES etudiant (id_etudiant) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return;
    }
    if (!$pdo->query("SHOW COLUMNS FROM etudiant_notifications LIKE 'lien'")->fetch()) {
        $pdo->exec('ALTER TABLE etudiant_notifications ADD COLUMN lien VARCHAR(255) NULL DEFAULT NULL AFTER message_body');
    }
}

function student_notification_link(?string $link): ?string
{
    $link = trim((string) $link);
    if ($link === '' || strlen($link) > 255 || !preg_match('/^dashboard\.php\?panel=cours(?:&cours=\d{1,10}(?:&dossier=\d{1,10})?)?\z/', $link)) {
        return null;
    }
    return $link;
}

function student_notify(PDO $pdo, int $studentId, string $sujet, string $messageBody, ?string $link = null): void
{
    student_notifications_ensure_tables($pdo);
    $sujet = trim($sujet);
    $messageBody = trim($messageBody);
    if ($studentId <= 0 || $sujet === '' || $messageBody === '') {
        return;
    }
    $query = $pdo->prepare(
        'INSERT INTO etudiant_notifications (id_etudiant, sujet, message_body, lien, is_read)
         VALUES (?, ?, ?, ?, 0)'
    );
    $query->execute([$studentId, mb_substr($sujet, 0, 180), $messageBody, student_notification_link($link)]);
}

function attestation_format_pickup_datetime(string $raw): ?string
{
    $raw = trim($raw);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/', $raw, $match)) {
        return null;
    }
    $year = (int) $match[1];
    $month = (int) $match[2];
    $day = (int) $match[3];
    $hour = (int) $match[4];
    $minute = (int) $match[5];
    if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59) {
        return null;
    }
    return sprintf('%02d/%02d/%04d à %02d:%02d', $day, $month, $year, $hour, $minute);
}

function attestation_pickup_from_request(): ?string
{
    $raw = trim((string) ($_POST['date_retrait'] ?? ''));
    $formatted = $raw !== '' ? attestation_format_pickup_datetime($raw) : null;
    if ($formatted !== null) {
        return $formatted;
    }

    $day = trim((string) ($_POST['date_retrait_jour'] ?? ''));
    $hour = trim((string) ($_POST['date_retrait_heure'] ?? ''));
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $day, $dateMatch)) {
        $day = $dateMatch[3] . '-' . $dateMatch[2] . '-' . $dateMatch[1];
    }
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $hour, $timeMatch)) {
        $hour = sprintf('%02d:%02d', (int) $timeMatch[1], (int) $timeMatch[2]);
    }
    if ($day === '' || $hour === '') {
        return null;
    }
    return attestation_format_pickup_datetime($day . 'T' . $hour);
}

function student_notifications_unread_count(PDO $pdo, int $studentId): int
{
    student_notifications_ensure_tables($pdo);
    $query = $pdo->prepare(
        'SELECT COUNT(*) FROM etudiant_notifications WHERE id_etudiant = ? AND is_read = 0'
    );
    $query->execute([$studentId]);
    return (int) $query->fetchColumn();
}

function student_notifications_latest(PDO $pdo, int $studentId, int $limit = 25): array
{
    student_notifications_ensure_tables($pdo);
    $limit = max(1, min(50, $limit));
    $query = $pdo->prepare(
        'SELECT id_notif, sujet, message_body, lien, is_read, date_creation
         FROM etudiant_notifications
         WHERE id_etudiant = ?
         ORDER BY date_creation DESC, id_notif DESC
         LIMIT ' . $limit
    );
    $query->execute([$studentId]);
    $messages = [];
    foreach ($query->fetchAll() as $row) {
        $created = strtotime((string) $row['date_creation']) ?: time();
        $messages[] = [
            'id' => (int) $row['id_notif'],
            'sujet' => (string) $row['sujet'],
            'message_body' => (string) $row['message_body'],
            'link' => student_notification_link($row['lien'] ?? null) ?? '',
            'is_read' => (int) $row['is_read'] === 1 ? 1 : 0,
            'date' => date('d/m/Y H:i', $created),
        ];
    }
    return $messages;
}

function student_notification_mark_read(PDO $pdo, int $studentId, int $notificationId): bool
{
    student_notifications_ensure_tables($pdo);
    if ($studentId <= 0 || $notificationId <= 0) {
        return false;
    }
    $query = $pdo->prepare(
        'UPDATE etudiant_notifications
         SET is_read = 1
         WHERE id_notif = ? AND id_etudiant = ?'
    );
    $query->execute([$notificationId, $studentId]);
    return $query->rowCount() > 0;
}

function student_attestation_board(PDO $pdo, int $studentId): array
{
    attestation_ensure_tables($pdo);
    $query = $pdo->prepare(
        'SELECT statut, date_acceptation, date_demande
         FROM demande_attestation
         WHERE id_etudiant = ?
         ORDER BY date_demande DESC, id_demande DESC
         LIMIT 1'
    );
    $query->execute([$studentId]);
    $row = $query->fetch();
    if (!$row) {
        return ['state' => 'none', 'message' => ''];
    }
    if ((string) $row['statut'] === 'acceptee') {
        $when = (string) ($row['date_acceptation'] ?: $row['date_demande']);
        $stamp = strtotime($when) ?: time();
        return [
            'state' => 'acceptee',
            'message' => 'L’attestation est acceptée le ' . date('d/m/Y à H:i', $stamp) . '.',
        ];
    }
    return [
        'state' => 'en_attente',
        'message' => 'Déjà envoyée et en file d’attente.',
    ];
}

function student_notifications_payload(PDO $pdo, int $studentId): array
{
    $messages = student_notifications_latest($pdo, $studentId);
    $unread = student_notifications_unread_count($pdo, $studentId);
    $latestId = $messages[0]['id'] ?? 0;
    $attestation = student_attestation_board($pdo, $studentId);
    return [
        'unread' => $unread,
        'stamp' => $unread . ':' . $latestId . ':' . count($messages) . ':' . $attestation['state'],
        'messages' => $messages,
        'attestation' => $attestation,
    ];
}

function ensure_etudiant_compte_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS etudiant_compte (
          id_compte INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_etudiant INT UNSIGNED NOT NULL,
          password VARCHAR(255) NOT NULL,
          active TINYINT(1) NOT NULL DEFAULT 1,
          auth_generation INT UNSIGNED NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_compte),
          UNIQUE KEY uq_etudiant_compte (id_etudiant),
          CONSTRAINT fk_etudiant_compte FOREIGN KEY (id_etudiant) REFERENCES etudiant (id_etudiant) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (!$pdo->query("SHOW COLUMNS FROM etudiant_compte LIKE 'auth_generation'")->fetch()) {
        $pdo->exec('ALTER TABLE etudiant_compte ADD COLUMN auth_generation INT UNSIGNED NOT NULL DEFAULT 1');
    }
    $ready = true;
}

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
    $semesters = study_level_periods($level);
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
    $policy = attendance_policy();
    return (float) $policy['expected_hours'] * ((float) $policy['missed_percent'] / 100);
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
    return (float) attendance_policy()['session_hours'];
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
    $attendance = attendance_policy();
    $limitHours = presence_semester_absence_limit_hours();
    $matiereLimit = (int) $attendance['max_absences_subject'];
    $semesterCode = (string) $attendance['semester_code'];

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
    $semestreQuery->execute([$semesterCode, $limitHours]);
    $semestreRows = $semestreQuery->fetchAll();
    $matiereRows = array_values(array_filter(
        $matiereRows,
        static fn (array $row): bool => !isset($overrides['matiere:' . (int) $row['id_etudiant'] . ':' . (int) $row['id_mat']])
    ));
    $semestreRows = array_values(array_filter(
        $semestreRows,
        static function (array $row) use ($overrides, $semesterCode): bool {
            return !isset($overrides['semestre:' . (int) $row['id_etudiant'] . ':' . $semesterCode]);
        }
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
            'semestre_total_hours' => (float) $attendance['expected_hours'],
            'semestre_ratio' => ((float) $attendance['missed_percent']) / 100,
            'semestre_code' => $semesterCode,
            'session_hours' => (float) $attendance['session_hours'],
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

function flash(string $kind, string $message): void
{
    $_SESSION['flash'] = [
        'kind' => $kind === 'danger' ? 'danger' : 'success',
        'message' => $message,
    ];
}

function flash_show(): void
{
    if (empty($_SESSION['flash']['message'])) {
        return;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $kind = ($flash['kind'] ?? '') === 'danger' ? 'danger' : 'success';
    echo '<div class="alert alert-' . $kind . '" role="status">' . e((string) $flash['message']) . '</div>';
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
    if (!$pdo->query("SHOW COLUMNS FROM enseignant LIKE 'auth_generation'")->fetch()) {
        $pdo->exec('ALTER TABLE enseignant ADD COLUMN auth_generation INT UNSIGNED NOT NULL DEFAULT 1');
    }
}

function audit_record(string $category, string $action, string $summary): void
{
    if (!in_array($category, ['note', 'paiement', 'presence', 'compte', 'reglage', 'stage', 'cours'], true)) {
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
        } elseif ($role === 'etudiant') {
            $actorId = (int) ($_SESSION['id_etudiant'] ?? 0);
            $query = $pdo->prepare('SELECT nom, prenom FROM etudiant WHERE id_etudiant = ? LIMIT 1');
            $query->execute([$actorId]);
            $student = $query->fetch();
            $label = trim((string) (($student['prenom'] ?? '') . ' ' . ($student['nom'] ?? ''))) ?: 'Étudiant';
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

require_once __DIR__ . '/support/institution.php';
require_once __DIR__ . '/support/academic_policy.php';
require_once __DIR__ . '/support/course_library.php';
if (!in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), ['account_status.php', 'etudiant_inbox.php'], true)) {
    validate_current_account_status();
}
