<?php
declare(strict_types=1);

/**
 * Smart timetable schema, CRUD, validation, and publish sync to emploi_temps.
 */

function emploi_smart_default_weights(): array
{
    return [
        'even_spread' => 3,
        'avoid_student_overload' => 4,
        'avoid_gaps' => 2,
        'avoid_teacher_gaps' => 2,
        'avoid_early_late' => 1,
        'prefer_consecutive' => 2,
    ];
}

function emploi_smart_default_config(): array
{
    return [
        'label' => 'default',
        'working_days' => ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'],
        'morning_start' => '08:15',
        'morning_end' => '13:00',
        'afternoon_start' => '15:00',
        'afternoon_end' => '18:00',
        'break_start' => '13:00',
        'break_end' => '15:00',
        'slot_minutes' => 15,
        'default_cours_minutes' => 90,
        'default_td_minutes' => 90,
        'default_tp_minutes' => 180,
        'max_group_hours_day' => 8.0,
        'max_teacher_hours_day' => 8.0,
        'max_consecutive_minutes' => 180,
        'min_break_minutes' => 0,
        'semester_weeks' => 14,
        'soft_weights' => emploi_smart_default_weights(),
        'holidays' => [],
    ];
}

function emploi_smart_json_encode(mixed $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function emploi_smart_json_decode(?string $raw, mixed $fallback = []): mixed
{
    if ($raw === null || trim($raw) === '') {
        return $fallback;
    }
    $decoded = json_decode($raw, true);
    return $decoded === null ? $fallback : $decoded;
}

function emploi_smart_time_norm(string $value): string
{
    $value = trim($value);
    if (preg_match('/^\d{1,2}:\d{2}$/', $value)) {
        return sprintf('%02d:%02d:00', (int) explode(':', $value)[0], (int) explode(':', $value)[1]);
    }
    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $value)) {
        return $value;
    }
    return '00:00:00';
}

function emploi_smart_minutes(string $hhmm): int
{
    $parts = array_map('intval', explode(':', emploi_smart_time_norm($hhmm)));
    return ($parts[0] ?? 0) * 60 + ($parts[1] ?? 0);
}

function emploi_smart_hhmm(int $minutes): string
{
    $minutes = max(0, $minutes);
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function emploi_smart_overlaps(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
{
    return $aStart < $bEnd && $bStart < $aEnd;
}

function emploi_smart_ensure(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    $pdo = db();
    $statements = [
        "CREATE TABLE IF NOT EXISTS emploi_config (
          id_config INT UNSIGNED NOT NULL AUTO_INCREMENT,
          label VARCHAR(80) NOT NULL DEFAULT 'default',
          working_days TEXT NOT NULL,
          morning_start TIME NOT NULL DEFAULT '08:15:00',
          morning_end TIME NOT NULL DEFAULT '13:00:00',
          afternoon_start TIME NOT NULL DEFAULT '15:00:00',
          afternoon_end TIME NOT NULL DEFAULT '18:00:00',
          break_start TIME NOT NULL DEFAULT '13:00:00',
          break_end TIME NOT NULL DEFAULT '15:00:00',
          slot_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
          default_cours_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 90,
          default_td_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 90,
          default_tp_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 180,
          max_group_hours_day DECIMAL(4,1) NOT NULL DEFAULT 8.0,
          max_teacher_hours_day DECIMAL(4,1) NOT NULL DEFAULT 8.0,
          max_consecutive_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 180,
          min_break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          semester_weeks SMALLINT UNSIGNED NOT NULL DEFAULT 14,
          soft_weights TEXT NOT NULL,
          holidays TEXT NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_config)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS emploi_ressource (
          id_ressource INT UNSIGNED NOT NULL AUTO_INCREMENT,
          code VARCHAR(40) NOT NULL,
          nom VARCHAR(120) NOT NULL,
          type_ressource VARCHAR(20) NOT NULL,
          lab_category VARCHAR(80) NOT NULL DEFAULT '',
          capacite SMALLINT UNSIGNED NOT NULL DEFAULT 30,
          active SMALLINT NOT NULL DEFAULT 1,
          availability TEXT NOT NULL,
          equipment TEXT NOT NULL DEFAULT '',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_ressource),
          UNIQUE KEY uq_emploi_ressource_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS emploi_version (
          id_version INT UNSIGNED NOT NULL AUTO_INCREMENT,
          label VARCHAR(160) NOT NULL,
          status VARCHAR(20) NOT NULL DEFAULT 'draft',
          id_year INT UNSIGNED DEFAULT NULL,
          semestre VARCHAR(20) NOT NULL DEFAULT 'S1',
          id_level INT UNSIGNED DEFAULT NULL,
          id_spec INT UNSIGNED DEFAULT NULL,
          scope_json TEXT NOT NULL,
          config_json TEXT NOT NULL,
          quality_score DECIMAL(6,2) NOT NULL DEFAULT 0,
          created_by VARCHAR(120) NOT NULL DEFAULT '',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          published_at TIMESTAMP NULL DEFAULT NULL,
          PRIMARY KEY (id_version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS emploi_composante (
          id_composante INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_version INT UNSIGNED NOT NULL,
          id_mat INT UNSIGNED NOT NULL,
          type_seance VARCHAR(20) NOT NULL,
          id_enseignant INT UNSIGNED NOT NULL,
          id_groupe INT UNSIGNED NOT NULL,
          duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 90,
          sessions_per_week DECIMAL(4,2) NOT NULL DEFAULT 1,
          frequency VARCHAR(20) NOT NULL DEFAULT 'weekly',
          lab_category VARCHAR(80) NOT NULL DEFAULT '',
          students_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          preferred_resource_type VARCHAR(20) NOT NULL DEFAULT '',
          notes TEXT NOT NULL DEFAULT '',
          PRIMARY KEY (id_composante)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS emploi_session (
          id_session INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_version INT UNSIGNED NOT NULL,
          id_composante INT UNSIGNED DEFAULT NULL,
          id_groupe INT UNSIGNED NOT NULL,
          id_mat INT UNSIGNED NOT NULL,
          id_enseignant INT UNSIGNED NOT NULL,
          id_ressource INT UNSIGNED NOT NULL,
          type_seance VARCHAR(20) NOT NULL,
          jour VARCHAR(20) NOT NULL,
          semaine SMALLINT UNSIGNED NOT NULL DEFAULT 1,
          week_pattern VARCHAR(20) NOT NULL DEFAULT 'weekly',
          heure_debut TIME NOT NULL,
          heure_fin TIME NOT NULL,
          locked SMALLINT NOT NULL DEFAULT 0,
          PRIMARY KEY (id_session)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS emploi_generation_run (
          id_run INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_version INT UNSIGNED NOT NULL,
          requested_count INT UNSIGNED NOT NULL DEFAULT 0,
          scheduled_count INT UNSIGNED NOT NULL DEFAULT 0,
          unscheduled_count INT UNSIGNED NOT NULL DEFAULT 0,
          total_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
          quality_score DECIMAL(6,2) NOT NULL DEFAULT 0,
          stats_json TEXT NOT NULL,
          warnings_json TEXT NOT NULL,
          created_by VARCHAR(120) NOT NULL DEFAULT '',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_run)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS emploi_unscheduled (
          id_unscheduled INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_run INT UNSIGNED NOT NULL,
          id_composante INT UNSIGNED DEFAULT NULL,
          id_mat INT UNSIGNED NOT NULL,
          type_seance VARCHAR(20) NOT NULL,
          id_enseignant INT UNSIGNED NOT NULL,
          id_groupe INT UNSIGNED NOT NULL,
          duration_minutes SMALLINT UNSIGNED NOT NULL,
          required_resource VARCHAR(80) NOT NULL DEFAULT '',
          reason TEXT NOT NULL,
          suggestions TEXT NOT NULL,
          PRIMARY KEY (id_unscheduled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $exception) {
            error_log('emploi_smart_ensure: ' . $exception->getMessage());
        }
    }
    $count = (int) $pdo->query('SELECT COUNT(*) FROM emploi_config')->fetchColumn();
    if ($count === 0) {
        emploi_smart_save_config(emploi_smart_default_config());
    }
}

function emploi_smart_config_row_to_array(array $row): array
{
    $defaults = emploi_smart_default_config();
    return [
        'id_config' => (int) ($row['id_config'] ?? 0),
        'label' => (string) ($row['label'] ?? 'default'),
        'working_days' => emploi_smart_json_decode((string) ($row['working_days'] ?? ''), $defaults['working_days']),
        'morning_start' => substr(emploi_smart_time_norm((string) ($row['morning_start'] ?? '08:15')), 0, 5),
        'morning_end' => substr(emploi_smart_time_norm((string) ($row['morning_end'] ?? '13:00')), 0, 5),
        'afternoon_start' => substr(emploi_smart_time_norm((string) ($row['afternoon_start'] ?? '15:00')), 0, 5),
        'afternoon_end' => substr(emploi_smart_time_norm((string) ($row['afternoon_end'] ?? '18:00')), 0, 5),
        'break_start' => substr(emploi_smart_time_norm((string) ($row['break_start'] ?? '13:00')), 0, 5),
        'break_end' => substr(emploi_smart_time_norm((string) ($row['break_end'] ?? '15:00')), 0, 5),
        'slot_minutes' => (int) ($row['slot_minutes'] ?? 15),
        'default_cours_minutes' => (int) ($row['default_cours_minutes'] ?? 90),
        'default_td_minutes' => (int) ($row['default_td_minutes'] ?? 90),
        'default_tp_minutes' => (int) ($row['default_tp_minutes'] ?? 180),
        'max_group_hours_day' => (float) ($row['max_group_hours_day'] ?? 8),
        'max_teacher_hours_day' => (float) ($row['max_teacher_hours_day'] ?? 8),
        'max_consecutive_minutes' => (int) ($row['max_consecutive_minutes'] ?? 180),
        'min_break_minutes' => (int) ($row['min_break_minutes'] ?? 0),
        'semester_weeks' => (int) ($row['semester_weeks'] ?? 14),
        'soft_weights' => emploi_smart_json_decode((string) ($row['soft_weights'] ?? ''), $defaults['soft_weights']),
        'holidays' => emploi_smart_json_decode((string) ($row['holidays'] ?? ''), []),
    ];
}

function emploi_smart_get_config(?int $id = null): array
{
    emploi_smart_ensure();
    if ($id) {
        $q = db()->prepare('SELECT * FROM emploi_config WHERE id_config = ? LIMIT 1');
        $q->execute([$id]);
        $row = $q->fetch();
        if ($row) {
            return emploi_smart_config_row_to_array($row);
        }
    }
    $row = db()->query('SELECT * FROM emploi_config ORDER BY id_config ASC LIMIT 1')->fetch();
    return $row ? emploi_smart_config_row_to_array($row) : emploi_smart_default_config();
}

function emploi_smart_save_config(array $config, ?int $id = null): int
{
    emploi_smart_ensure();
    $pdo = db();
    $days = array_values(array_filter(array_map('strval', (array) ($config['working_days'] ?? []))));
    if ($days === []) {
        $days = emploi_smart_default_config()['working_days'];
    }
    $weights = (array) ($config['soft_weights'] ?? emploi_smart_default_weights());
    $holidays = array_values((array) ($config['holidays'] ?? []));
    $fields = [
        (string) ($config['label'] ?? 'default'),
        emploi_smart_json_encode($days),
        emploi_smart_time_norm((string) ($config['morning_start'] ?? '08:15')),
        emploi_smart_time_norm((string) ($config['morning_end'] ?? '13:00')),
        emploi_smart_time_norm((string) ($config['afternoon_start'] ?? '15:00')),
        emploi_smart_time_norm((string) ($config['afternoon_end'] ?? '18:00')),
        emploi_smart_time_norm((string) ($config['break_start'] ?? '13:00')),
        emploi_smart_time_norm((string) ($config['break_end'] ?? '15:00')),
        max(5, (int) ($config['slot_minutes'] ?? 15)),
        max(30, (int) ($config['default_cours_minutes'] ?? 90)),
        max(30, (int) ($config['default_td_minutes'] ?? 90)),
        max(30, (int) ($config['default_tp_minutes'] ?? 180)),
        max(1, (float) ($config['max_group_hours_day'] ?? 8)),
        max(1, (float) ($config['max_teacher_hours_day'] ?? 8)),
        max(30, (int) ($config['max_consecutive_minutes'] ?? 180)),
        max(0, (int) ($config['min_break_minutes'] ?? 0)),
        max(1, (int) ($config['semester_weeks'] ?? 14)),
        emploi_smart_json_encode($weights),
        emploi_smart_json_encode($holidays),
    ];
    if ($id) {
        $sql = 'UPDATE emploi_config SET label=?, working_days=?, morning_start=?, morning_end=?, afternoon_start=?, afternoon_end=?, break_start=?, break_end=?, slot_minutes=?, default_cours_minutes=?, default_td_minutes=?, default_tp_minutes=?, max_group_hours_day=?, max_teacher_hours_day=?, max_consecutive_minutes=?, min_break_minutes=?, semester_weeks=?, soft_weights=?, holidays=? WHERE id_config=?';
        $pdo->prepare($sql)->execute([...$fields, $id]);
        return $id;
    }
    $existing = (int) $pdo->query('SELECT id_config FROM emploi_config ORDER BY id_config ASC LIMIT 1')->fetchColumn();
    if ($existing > 0) {
        return emploi_smart_save_config($config, $existing);
    }
    $pdo->prepare(
        'INSERT INTO emploi_config (label, working_days, morning_start, morning_end, afternoon_start, afternoon_end, break_start, break_end, slot_minutes, default_cours_minutes, default_td_minutes, default_tp_minutes, max_group_hours_day, max_teacher_hours_day, max_consecutive_minutes, min_break_minutes, semester_weeks, soft_weights, holidays)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute($fields);
    return (int) $pdo->lastInsertId();
}

function emploi_smart_list_resources(bool $activeOnly = false): array
{
    emploi_smart_ensure();
    $sql = 'SELECT * FROM emploi_ressource';
    if ($activeOnly) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY type_ressource, lab_category, nom';
    return db()->query($sql)->fetchAll();
}

function emploi_smart_save_resource(array $data, ?int $id = null): int
{
    emploi_smart_ensure();
    $pdo = db();
    $type = (string) ($data['type_ressource'] ?? 'salle');
    if (!in_array($type, ['salle', 'amphi', 'labo'], true)) {
        throw new InvalidArgumentException('Type de ressource invalide.');
    }
    $code = trim((string) ($data['code'] ?? ''));
    $nom = trim((string) ($data['nom'] ?? ''));
    if ($nom === '') {
        throw new InvalidArgumentException('Le nom de la ressource est obligatoire.');
    }
    if ($id && $code === '') {
        $existingCode = $pdo->prepare('SELECT code FROM emploi_ressource WHERE id_ressource = ?');
        $existingCode->execute([$id]);
        $code = (string) ($existingCode->fetchColumn() ?: '');
    }
    if ($code === '') {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $nom) ?: 'RES') . '_' . substr(uniqid(), -4);
    }
    $fields = [
        $code,
        $nom,
        $type,
        trim((string) ($data['lab_category'] ?? '')),
        max(1, (int) ($data['capacite'] ?? 30)),
        !empty($data['active']) ? 1 : 0,
        emploi_smart_json_encode($data['availability'] ?? new stdClass()),
        trim((string) ($data['equipment'] ?? '')),
    ];
    if ($id) {
        $pdo->prepare(
            'UPDATE emploi_ressource SET code=?, nom=?, type_ressource=?, lab_category=?, capacite=?, active=?, availability=?, equipment=? WHERE id_ressource=?'
        )->execute([...$fields, $id]);
        return $id;
    }
    $pdo->prepare(
        'INSERT INTO emploi_ressource (code, nom, type_ressource, lab_category, capacite, active, availability, equipment) VALUES (?,?,?,?,?,?,?,?)'
    )->execute($fields);
    return (int) $pdo->lastInsertId();
}

function emploi_smart_bulk_create_resources(string $type, int $quantity, string $prefix, int $capacity = 30, string $labCategory = ''): array
{
    $ids = [];
    $quantity = max(0, min(50, $quantity));
    for ($i = 1; $i <= $quantity; $i++) {
        $name = trim($prefix) !== '' ? trim($prefix) . ' ' . $i : ucfirst($type) . ' ' . $i;
        $ids[] = emploi_smart_save_resource([
            'code' => strtoupper($type) . '_' . $i . '_' . substr(uniqid(), -3),
            'nom' => $name,
            'type_ressource' => $type,
            'lab_category' => $labCategory,
            'capacite' => $capacity,
            'active' => 1,
            'availability' => new stdClass(),
            'equipment' => '',
        ]);
    }
    return $ids;
}

function emploi_smart_delete_resource(int $id): void
{
    emploi_smart_ensure();
    db()->prepare('DELETE FROM emploi_ressource WHERE id_ressource = ?')->execute([$id]);
}

function emploi_smart_list_versions(?string $status = null): array
{
    emploi_smart_ensure();
    if ($status) {
        $q = db()->prepare('SELECT * FROM emploi_version WHERE status = ? ORDER BY id_version DESC');
        $q->execute([$status]);
        return $q->fetchAll();
    }
    return db()->query('SELECT * FROM emploi_version ORDER BY id_version DESC')->fetchAll();
}

function emploi_smart_get_version(int $id): ?array
{
    emploi_smart_ensure();
    $q = db()->prepare('SELECT * FROM emploi_version WHERE id_version = ? LIMIT 1');
    $q->execute([$id]);
    $row = $q->fetch() ?: null;
    if (!$row) {
        return null;
    }
    $row['scope'] = emploi_smart_json_decode((string) ($row['scope_json'] ?? ''), []);
    $row['config'] = emploi_smart_json_decode((string) ($row['config_json'] ?? ''), emploi_smart_default_config());
    return $row;
}

function emploi_smart_create_version(array $data): int
{
    emploi_smart_ensure();
    $pdo = db();
    $config = (array) ($data['config'] ?? emploi_smart_get_config());
    $scope = (array) ($data['scope'] ?? []);
    $pdo->prepare(
        'INSERT INTO emploi_version (label, status, id_year, semestre, id_level, id_spec, scope_json, config_json, quality_score, created_by)
         VALUES (?,?,?,?,?,?,?,?,0,?)'
    )->execute([
        trim((string) ($data['label'] ?? 'Emploi')) ?: 'Emploi',
        (string) ($data['status'] ?? 'draft'),
        ($data['id_year'] ?? null) !== null && (int) $data['id_year'] > 0 ? (int) $data['id_year'] : null,
        (string) ($data['semestre'] ?? 'S1'),
        ($data['id_level'] ?? null) !== null && (int) $data['id_level'] > 0 ? (int) $data['id_level'] : null,
        ($data['id_spec'] ?? null) !== null && (int) $data['id_spec'] > 0 ? (int) $data['id_spec'] : null,
        emploi_smart_json_encode($scope),
        emploi_smart_json_encode($config),
        (string) ($data['created_by'] ?? ''),
    ]);
    return (int) $pdo->lastInsertId();
}

function emploi_smart_update_version(int $id, array $data): void
{
    emploi_smart_ensure();
    $current = emploi_smart_get_version($id);
    if (!$current) {
        throw new InvalidArgumentException('Version introuvable.');
    }
    $label = array_key_exists('label', $data) ? trim((string) $data['label']) : (string) $current['label'];
    $status = array_key_exists('status', $data) ? (string) $data['status'] : (string) $current['status'];
    $allowed = ['draft', 'review', 'approved', 'published', 'archived'];
    if (!in_array($status, $allowed, true)) {
        throw new InvalidArgumentException('Statut de version invalide.');
    }
    $scope = array_key_exists('scope', $data) ? (array) $data['scope'] : (array) $current['scope'];
    $config = array_key_exists('config', $data) ? (array) $data['config'] : (array) $current['config'];
    $score = array_key_exists('quality_score', $data) ? (float) $data['quality_score'] : (float) $current['quality_score'];
    $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : ($current['published_at'] ?? null);
    db()->prepare(
        'UPDATE emploi_version SET label=?, status=?, id_year=?, semestre=?, id_level=?, id_spec=?, scope_json=?, config_json=?, quality_score=?, updated_at=CURRENT_TIMESTAMP, published_at=? WHERE id_version=?'
    )->execute([
        $label !== '' ? $label : (string) $current['label'],
        $status,
        array_key_exists('id_year', $data) ? (((int) $data['id_year']) ?: null) : ($current['id_year'] !== null ? (int) $current['id_year'] : null),
        array_key_exists('semestre', $data) ? (string) $data['semestre'] : (string) $current['semestre'],
        array_key_exists('id_level', $data) ? (((int) $data['id_level']) ?: null) : ($current['id_level'] !== null ? (int) $current['id_level'] : null),
        array_key_exists('id_spec', $data) ? (((int) $data['id_spec']) ?: null) : ($current['id_spec'] !== null ? (int) $current['id_spec'] : null),
        emploi_smart_json_encode($scope),
        emploi_smart_json_encode($config),
        $score,
        $publishedAt,
        $id,
    ]);
}

function emploi_smart_list_composantes(int $versionId): array
{
    emploi_smart_ensure();
    $q = db()->prepare(
        'SELECT c.*, m.code_ecue, m.description_ecue, g.type_groupe, g.numero, g.semestre,
                en.nom AS ens_nom, en.prenom AS ens_prenom
         FROM emploi_composante c
         JOIN matiere m ON m.id_mat = c.id_mat
         JOIN groupe g ON g.id_groupe = c.id_groupe
         JOIN enseignant en ON en.id_enseignant = c.id_enseignant
         WHERE c.id_version = ?
         ORDER BY m.code_ecue, c.type_seance, g.type_groupe, g.numero'
    );
    $q->execute([$versionId]);
    return $q->fetchAll();
}

function emploi_smart_replace_composantes(int $versionId, array $rows): void
{
    emploi_smart_ensure();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM emploi_composante WHERE id_version = ?')->execute([$versionId]);
        $insert = $pdo->prepare(
            'INSERT INTO emploi_composante (id_version, id_mat, type_seance, id_enseignant, id_groupe, duration_minutes, sessions_per_week, frequency, lab_category, students_count, preferred_resource_type, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($rows as $row) {
            $type = (string) ($row['type_seance'] ?? '');
            if (!in_array($type, ['Cours', 'TD', 'TP'], true)) {
                continue;
            }
            $insert->execute([
                $versionId,
                (int) ($row['id_mat'] ?? 0),
                $type,
                (int) ($row['id_enseignant'] ?? 0),
                (int) ($row['id_groupe'] ?? 0),
                max(15, (int) ($row['duration_minutes'] ?? 90)),
                max(0.25, (float) ($row['sessions_per_week'] ?? 1)),
                (string) ($row['frequency'] ?? 'weekly'),
                trim((string) ($row['lab_category'] ?? '')),
                max(0, (int) ($row['students_count'] ?? 0)),
                (string) ($row['preferred_resource_type'] ?? ''),
                trim((string) ($row['notes'] ?? '')),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function emploi_smart_list_sessions(int $versionId, array $filters = []): array
{
    emploi_smart_ensure();
    $sql = 'SELECT s.*, r.nom AS ressource_nom, r.type_ressource, r.lab_category,
                   m.code_ecue, m.description_ecue, g.type_groupe, g.numero,
                   en.nom AS ens_nom, en.prenom AS ens_prenom
            FROM emploi_session s
            JOIN emploi_ressource r ON r.id_ressource = s.id_ressource
            JOIN matiere m ON m.id_mat = s.id_mat
            JOIN groupe g ON g.id_groupe = s.id_groupe
            JOIN enseignant en ON en.id_enseignant = s.id_enseignant
            WHERE s.id_version = ?';
    $params = [$versionId];
    foreach (['id_groupe', 'id_enseignant', 'id_ressource', 'id_mat', 'type_seance'] as $key) {
        if (!empty($filters[$key])) {
            $sql .= ' AND s.' . $key . ' = ?';
            $params[] = $filters[$key];
        }
    }
    $sql .= ' ORDER BY FIELD(s.jour, "Lundi","Mardi","Mercredi","Jeudi","Vendredi","Samedi"), s.heure_debut, s.semaine';
    $q = db()->prepare($sql);
    $q->execute($params);
    return $q->fetchAll();
}

function emploi_smart_clear_unlocked_sessions(int $versionId): void
{
    emploi_smart_ensure();
    db()->prepare('DELETE FROM emploi_session WHERE id_version = ? AND locked = 0')->execute([$versionId]);
}

function emploi_smart_insert_sessions(int $versionId, array $sessions): void
{
    emploi_smart_ensure();
    $pdo = db();
    $insert = $pdo->prepare(
        'INSERT INTO emploi_session (id_version, id_composante, id_groupe, id_mat, id_enseignant, id_ressource, type_seance, jour, semaine, week_pattern, heure_debut, heure_fin, locked)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($sessions as $session) {
        $insert->execute([
            $versionId,
            $session['id_composante'] ?? null,
            (int) $session['id_groupe'],
            (int) $session['id_mat'],
            (int) $session['id_enseignant'],
            (int) $session['id_ressource'],
            (string) $session['type_seance'],
            (string) $session['jour'],
            (int) ($session['semaine'] ?? 1),
            (string) ($session['week_pattern'] ?? 'weekly'),
            emploi_smart_time_norm((string) $session['heure_debut']),
            emploi_smart_time_norm((string) $session['heure_fin']),
            !empty($session['locked']) ? 1 : 0,
        ]);
    }
}

function emploi_smart_save_generation_run(int $versionId, array $result, string $createdBy = ''): int
{
    emploi_smart_ensure();
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO emploi_generation_run (id_version, requested_count, scheduled_count, unscheduled_count, total_hours, quality_score, stats_json, warnings_json, created_by)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $versionId,
        (int) ($result['requested_count'] ?? 0),
        (int) ($result['scheduled_count'] ?? 0),
        (int) ($result['unscheduled_count'] ?? 0),
        (float) ($result['total_hours'] ?? 0),
        (float) ($result['quality_score'] ?? 0),
        emploi_smart_json_encode($result['stats'] ?? []),
        emploi_smart_json_encode($result['warnings'] ?? []),
        $createdBy,
    ]);
    $runId = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare(
        'INSERT INTO emploi_unscheduled (id_run, id_composante, id_mat, type_seance, id_enseignant, id_groupe, duration_minutes, required_resource, reason, suggestions)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ((array) ($result['unscheduled'] ?? []) as $row) {
        $insert->execute([
            $runId,
            $row['id_composante'] ?? null,
            (int) ($row['id_mat'] ?? 0),
            (string) ($row['type_seance'] ?? ''),
            (int) ($row['id_enseignant'] ?? 0),
            (int) ($row['id_groupe'] ?? 0),
            (int) ($row['duration_minutes'] ?? 0),
            (string) ($row['required_resource'] ?? ''),
            (string) ($row['reason'] ?? ''),
            emploi_smart_json_encode($row['suggestions'] ?? []),
        ]);
    }
    return $runId;
}

function emploi_smart_latest_run(int $versionId): ?array
{
    emploi_smart_ensure();
    $q = db()->prepare('SELECT * FROM emploi_generation_run WHERE id_version = ? ORDER BY id_run DESC LIMIT 1');
    $q->execute([$versionId]);
    $row = $q->fetch() ?: null;
    if (!$row) {
        return null;
    }
    $row['stats'] = emploi_smart_json_decode((string) ($row['stats_json'] ?? ''), []);
    $row['warnings'] = emploi_smart_json_decode((string) ($row['warnings_json'] ?? ''), []);
    $u = db()->prepare('SELECT * FROM emploi_unscheduled WHERE id_run = ? ORDER BY id_unscheduled');
    $u->execute([(int) $row['id_run']]);
    $row['unscheduled'] = $u->fetchAll();
    return $row;
}

function emploi_smart_session_conflict(array $candidate, array $existing): ?string
{
    $cStart = emploi_smart_minutes((string) $candidate['heure_debut']);
    $cEnd = emploi_smart_minutes((string) $candidate['heure_fin']);
    $cWeek = (int) ($candidate['semaine'] ?? 1);
    $cPattern = (string) ($candidate['week_pattern'] ?? 'weekly');
    foreach ($existing as $session) {
        if ((string) $session['jour'] !== (string) $candidate['jour']) {
            continue;
        }
        $sWeek = (int) ($session['semaine'] ?? 1);
        $sPattern = (string) ($session['week_pattern'] ?? 'weekly');
        $weekClash = false;
        if ($cPattern === 'weekly' || $sPattern === 'weekly') {
            $weekClash = true;
        } elseif ($cPattern === $sPattern && $cPattern !== 'weekly') {
            $weekClash = $cWeek === $sWeek || $cPattern === 'A' || $cPattern === 'B';
            if (in_array($cPattern, ['A', 'B'], true) && $cPattern === $sPattern) {
                $weekClash = true;
            }
        } else {
            $weekClash = $cWeek === $sWeek;
        }
        if (!$weekClash && $cWeek !== $sWeek && $cPattern !== 'weekly' && $sPattern !== 'weekly') {
            continue;
        }
        if ($cPattern !== 'weekly' && $sPattern !== 'weekly' && $cPattern !== $sPattern && !(($cPattern === 'A' || $cPattern === 'B') && $cPattern === $sPattern)) {
            if ($cWeek !== $sWeek) {
                continue;
            }
        }
        $sStart = emploi_smart_minutes((string) $session['heure_debut']);
        $sEnd = emploi_smart_minutes((string) $session['heure_fin']);
        if (!emploi_smart_overlaps($cStart, $cEnd, $sStart, $sEnd)) {
            continue;
        }
        if ((int) $candidate['id_enseignant'] === (int) $session['id_enseignant']) {
            return 'L’enseignant est déjà occupé sur ce créneau.';
        }
        if ((int) $candidate['id_groupe'] === (int) $session['id_groupe']) {
            return 'Le groupe est déjà occupé sur ce créneau.';
        }
        if ((int) $candidate['id_ressource'] === (int) $session['id_ressource']) {
            return 'La ressource est déjà réservée (exclusivité stricte).';
        }
    }
    return null;
}

function emploi_smart_update_session(int $sessionId, array $data): void
{
    emploi_smart_ensure();
    $q = db()->prepare('SELECT * FROM emploi_session WHERE id_session = ? LIMIT 1');
    $q->execute([$sessionId]);
    $current = $q->fetch();
    if (!$current) {
        throw new InvalidArgumentException('Séance introuvable.');
    }
    $versionId = (int) $current['id_version'];
    $version = emploi_smart_get_version($versionId);
    if ($version && in_array((string) $version['status'], ['published', 'archived'], true) && empty($data['force'])) {
        throw new InvalidArgumentException('Version publiée ou archivée : modification refusée sans autorisation explicite.');
    }
    $updated = array_merge($current, $data);
    $others = array_values(array_filter(
        emploi_smart_list_sessions($versionId),
        static fn(array $row): bool => (int) $row['id_session'] !== $sessionId
    ));
    $conflict = emploi_smart_session_conflict([
        'jour' => (string) $updated['jour'],
        'heure_debut' => (string) $updated['heure_debut'],
        'heure_fin' => (string) $updated['heure_fin'],
        'semaine' => (int) ($updated['semaine'] ?? 1),
        'week_pattern' => (string) ($updated['week_pattern'] ?? 'weekly'),
        'id_enseignant' => (int) $updated['id_enseignant'],
        'id_groupe' => (int) $updated['id_groupe'],
        'id_ressource' => (int) $updated['id_ressource'],
    ], $others);
    if ($conflict) {
        throw new InvalidArgumentException($conflict);
    }
    $resources = emploi_smart_list_resources(true);
    $resource = null;
    foreach ($resources as $res) {
        if ((int) $res['id_ressource'] === (int) $updated['id_ressource']) {
            $resource = $res;
            break;
        }
    }
    if (!$resource) {
        throw new InvalidArgumentException('Ressource introuvable ou inactive.');
    }
    db()->prepare(
        'UPDATE emploi_session SET id_enseignant=?, id_ressource=?, jour=?, semaine=?, week_pattern=?, heure_debut=?, heure_fin=?, locked=? WHERE id_session=?'
    )->execute([
        (int) $updated['id_enseignant'],
        (int) $updated['id_ressource'],
        (string) $updated['jour'],
        (int) ($updated['semaine'] ?? 1),
        (string) ($updated['week_pattern'] ?? 'weekly'),
        emploi_smart_time_norm((string) $updated['heure_debut']),
        emploi_smart_time_norm((string) $updated['heure_fin']),
        !empty($updated['locked']) ? 1 : 0,
        $sessionId,
    ]);
}

function emploi_smart_delete_session(int $sessionId): void
{
    emploi_smart_ensure();
    db()->prepare('DELETE FROM emploi_session WHERE id_session = ?')->execute([$sessionId]);
}

function emploi_smart_publish(int $versionId, bool $force = false): void
{
    emploi_smart_ensure();
    $version = emploi_smart_get_version($versionId);
    if (!$version) {
        throw new InvalidArgumentException('Version introuvable.');
    }
    if (in_array((string) $version['status'], ['published'], true) && !$force) {
        throw new InvalidArgumentException('Cette version est déjà publiée.');
    }
    if (in_array((string) $version['status'], ['archived'], true) && !$force) {
        throw new InvalidArgumentException('Version archivée : republier nécessite une autorisation explicite.');
    }
    $sessions = emploi_smart_list_sessions($versionId);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $groupIds = array_values(array_unique(array_map(static fn(array $s): int => (int) $s['id_groupe'], $sessions)));
        if ($groupIds) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $pdo->prepare('DELETE FROM emploi_temps WHERE id_groupe IN (' . $placeholders . ')')->execute($groupIds);
        }
        $insert = $pdo->prepare(
            'INSERT INTO emploi_temps (id_groupe, id_mat, id_enseignant, jour, semaine, heure_debut, heure_fin, type_seance, salle)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        foreach ($sessions as $session) {
            $insert->execute([
                (int) $session['id_groupe'],
                (int) $session['id_mat'],
                (int) $session['id_enseignant'],
                (string) $session['jour'],
                (int) $session['semaine'],
                emploi_smart_time_norm((string) $session['heure_debut']),
                emploi_smart_time_norm((string) $session['heure_fin']),
                (string) $session['type_seance'],
                (string) $session['ressource_nom'],
            ]);
        }
        $pdo->prepare(
            "UPDATE emploi_version SET status='published', published_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id_version=?"
        )->execute([$versionId]);
        $pdo->prepare(
            "UPDATE emploi_version SET status='archived', updated_at=CURRENT_TIMESTAMP WHERE status='published' AND id_version <> ?"
        )->execute([$versionId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function emploi_smart_groups_for_semestre(string $semestre, ?string $type = null): array
{
    $sql = 'SELECT * FROM groupe WHERE semestre = ?';
    $params = [$semestre];
    if ($type) {
        $sql .= ' AND type_groupe = ?';
        $params[] = $type;
    }
    $sql .= ' ORDER BY type_groupe, numero';
    $q = db()->prepare($sql);
    $q->execute($params);
    return $q->fetchAll();
}

function emploi_smart_matieres_for_semestre(string $semestre): array
{
    $q = db()->prepare('SELECT * FROM matiere WHERE semestre = ? ORDER BY code_ecue');
    $q->execute([$semestre]);
    return $q->fetchAll();
}

function emploi_smart_teachers_for_matiere_groupe(int $matId, int $groupId): array
{
    $q = db()->prepare(
        'SELECT DISTINCT e.id_enseignant, e.nom, e.prenom
         FROM enseignant e
         JOIN enseignant_affectation a ON a.id_enseignant = e.id_enseignant
         WHERE a.id_mat = ? AND a.id_groupe = ? AND e.active = 1
         ORDER BY e.nom, e.prenom'
    );
    $q->execute([$matId, $groupId]);
    $rows = $q->fetchAll();
    if ($rows) {
        return $rows;
    }
    return db()->query(
        'SELECT id_enseignant, nom, prenom FROM enseignant WHERE active = 1 ORDER BY nom, prenom'
    )->fetchAll();
}

function emploi_smart_group_student_count(int $groupId): int
{
    $q = db()->prepare('SELECT COUNT(*) FROM groupe_etudiant WHERE id_groupe = ?');
    $q->execute([$groupId]);
    return (int) $q->fetchColumn();
}

function emploi_smart_default_resource_type(string $typeSeance): string
{
    return match ($typeSeance) {
        'Cours' => 'amphi',
        'TD' => 'salle',
        'TP' => 'labo',
        default => 'salle',
    };
}

function emploi_smart_export_csv(int $versionId): string
{
    $sessions = emploi_smart_list_sessions($versionId);
    $lines = ['jour;debut;fin;semaine;type;matiere;groupe;enseignant;ressource;verrouille'];
    foreach ($sessions as $s) {
        $lines[] = implode(';', [
            $s['jour'],
            substr((string) $s['heure_debut'], 0, 5),
            substr((string) $s['heure_fin'], 0, 5),
            $s['semaine'],
            $s['type_seance'],
            $s['code_ecue'],
            $s['type_groupe'] . ' ' . $s['numero'],
            trim($s['ens_prenom'] . ' ' . $s['ens_nom']),
            $s['ressource_nom'],
            !empty($s['locked']) ? 'oui' : 'non',
        ]);
    }
    return implode("\n", $lines) . "\n";
}

function emploi_smart_format_slot_label(int $startMin, int $endMin): string
{
    $fmt = static function (int $m): string {
        return sprintf('%02dh%02d', intdiv($m, 60), $m % 60);
    };
    return $fmt($startMin) . ' - ' . $fmt($endMin);
}

/**
 * Build ordered time-column breakpoints from sessions + config windows.
 * @return list<array{start:int,end:int,label:string}>
 */
function emploi_smart_time_columns(array $sessions, array $config = []): array
{
    $points = [];
    foreach ($sessions as $s) {
        $points[emploi_smart_minutes((string) $s['heure_debut'])] = true;
        $points[emploi_smart_minutes((string) $s['heure_fin'])] = true;
    }
    foreach (['morning_start', 'morning_end', 'afternoon_start', 'afternoon_end'] as $key) {
        if (!empty($config[$key])) {
            $points[emploi_smart_minutes((string) $config[$key])] = true;
        }
    }
    if ($points === []) {
        foreach ([emploi_smart_minutes('08:15'), emploi_smart_minutes('09:45'), emploi_smart_minutes('11:15'), emploi_smart_minutes('13:00'), emploi_smart_minutes('15:00'), emploi_smart_minutes('16:30'), emploi_smart_minutes('18:00')] as $p) {
            $points[$p] = true;
        }
    }
    $sorted = array_keys($points);
    sort($sorted, SORT_NUMERIC);
    $columns = [];
    for ($i = 0; $i < count($sorted) - 1; $i++) {
        $start = (int) $sorted[$i];
        $end = (int) $sorted[$i + 1];
        if ($end <= $start) {
            continue;
        }
        // Skip pure break gaps longer than 90 min with no session touching them
        $hasSession = false;
        foreach ($sessions as $s) {
            $sStart = emploi_smart_minutes((string) $s['heure_debut']);
            $sEnd = emploi_smart_minutes((string) $s['heure_fin']);
            if (emploi_smart_overlaps($start, $end, $sStart, $sEnd) || $sStart === $start || $sEnd === $end) {
                $hasSession = true;
                break;
            }
        }
        if (!$hasSession && ($end - $start) >= 90 && count($sessions) > 0) {
            // keep short empty slots for readability; drop long idle gaps
            continue;
        }
        $columns[] = [
            'start' => $start,
            'end' => $end,
            'label' => emploi_smart_format_slot_label($start, $end),
        ];
    }
    if ($columns === []) {
        $columns[] = ['start' => emploi_smart_minutes('08:15'), 'end' => emploi_smart_minutes('18:00'), 'label' => '08h15 - 18h00'];
    }
    return $columns;
}

/**
 * Classic university timetable HTML: days as rows, hours as columns, horizontal scroll.
 *
 * @param array $options editable=bool, days=list, config=array, theme=light|dark
 */
function emploi_smart_render_timetable_html(array $sessions, array $options = []): string
{
    $config = (array) ($options['config'] ?? []);
    $days = array_values((array) ($options['days'] ?? $config['working_days'] ?? ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi']));
    $editable = !empty($options['editable']);
    $theme = (string) ($options['theme'] ?? 'light');
    $columns = emploi_smart_time_columns($sessions, $config);
    $colCount = count($columns);

    $byDay = [];
    foreach ($days as $day) {
        $byDay[$day] = [];
    }
    foreach ($sessions as $s) {
        $day = (string) $s['jour'];
        if (!isset($byDay[$day])) {
            $byDay[$day] = [];
        }
        $byDay[$day][] = $s;
    }

    $typeDot = static function (string $type): string {
        $color = match ($type) {
            'Cours' => '#dc3545',
            'TD' => '#0d6efd',
            'TP' => '#198754',
            default => '#6c757d',
        };
        return '<span class="emploi-tt-dot" style="background:' . $color . '" title="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '"></span>';
    };

    $html = '<style>
.emploi-tt-scroll{overflow-x:auto;overflow-y:auto;max-width:100%;width:100%;-webkit-overflow-scrolling:touch;border:1px solid #cfd4da;border-radius:8px;background:#fff;color:#111}
.emploi-tt{border-collapse:collapse;min-width:max(100%,1100px);width:max-content;font-size:12px;line-height:1.25;background:#fff;color:#111}
.emploi-tt th,.emploi-tt td{border:1px solid #b9c0c8;padding:6px 8px;vertical-align:top}
.emploi-tt thead th{background:#eef1f4;font-weight:600;white-space:nowrap;text-align:center;position:sticky;top:0;z-index:3}
.emploi-tt th.emploi-tt-day{position:sticky;left:0;z-index:4;background:#e8ecf0;min-width:88px;width:88px;text-align:left;font-weight:700}
.emploi-tt td.emploi-tt-day{position:sticky;left:0;z-index:2;background:#f7f8fa;font-weight:700;min-width:88px;width:88px}
.emploi-tt td.emploi-tt-slot{min-width:130px;max-width:200px;background:#fff}
.emploi-tt td.emploi-tt-empty{background:#fafbfc;color:#9aa3ad}
.emploi-tt-cell{position:relative;padding:4px 14px 6px 0}
.emploi-tt-cell+.emploi-tt-cell{border-top:1px dashed #d0d5db;margin-top:4px;padding-top:8px}
.emploi-tt-dot{position:absolute;top:4px;right:0;width:9px;height:9px;border-radius:50%;display:inline-block}
.emploi-tt-code{font-weight:700;display:block}
.emploi-tt-meta{display:block;color:#333}
.emploi-tt-sub{display:block;color:#5c6670;font-size:11px}
.emploi-tt-legend{display:flex;flex-wrap:wrap;gap:12px;margin:.75rem 0;font-size:12px;color:inherit}
.emploi-tt-legend span{display:inline-flex;align-items:center;gap:6px}
.emploi-tt-legend i{width:10px;height:10px;border-radius:50%;display:inline-block}
.emploi-tt-edit summary{cursor:pointer;color:#0d6efd;font-size:11px;margin-top:4px;list-style:none}
.emploi-tt-edit form{margin-top:6px;display:grid;gap:4px}
' . ($theme === 'dark' ? '.emploi-tt-wrap{margin-top:1rem}' : '') . '
@media print{.emploi-tt-scroll{overflow:visible;border:none}.emploi-tt{min-width:100%;width:100%;font-size:10px}.emploi-tt-edit{display:none!important}}
</style>';

    $html .= '<div class="emploi-tt-legend">'
        . '<span><i style="background:#dc3545"></i> Cours</span>'
        . '<span><i style="background:#0d6efd"></i> TD</span>'
        . '<span><i style="background:#198754"></i> TP</span>'
        . '<span>Faites défiler horizontalement pour voir tous les créneaux</span>'
        . '</div>';

    $html .= '<div class="emploi-tt-wrap"><div class="emploi-tt-scroll"><table class="emploi-tt"><thead><tr>';
    $html .= '<th class="emploi-tt-day">Jour</th>';
    foreach ($columns as $col) {
        $html .= '<th>' . htmlspecialchars($col['label'], ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($days as $day) {
        $daySessions = $byDay[$day] ?? [];
        usort($daySessions, static function (array $a, array $b): int {
            return emploi_smart_minutes((string) $a['heure_debut']) <=> emploi_smart_minutes((string) $b['heure_debut']);
        });
        $html .= '<tr><td class="emploi-tt-day">' . htmlspecialchars($day, ENT_QUOTES, 'UTF-8') . '</td>';
        $colIndex = 0;
        $used = array_fill(0, $colCount, false);
        // Pre-map sessions onto starting column + colspan
        $placements = [];
        foreach ($daySessions as $s) {
            $sStart = emploi_smart_minutes((string) $s['heure_debut']);
            $sEnd = emploi_smart_minutes((string) $s['heure_fin']);
            $startIdx = null;
            $endIdx = null;
            for ($i = 0; $i < $colCount; $i++) {
                if ($columns[$i]['start'] === $sStart || ($columns[$i]['start'] <= $sStart && $sStart < $columns[$i]['end'])) {
                    $startIdx = $i;
                }
                if ($columns[$i]['end'] === $sEnd || ($columns[$i]['start'] < $sEnd && $sEnd <= $columns[$i]['end'])) {
                    $endIdx = $i;
                }
            }
            if ($startIdx === null) {
                for ($i = 0; $i < $colCount; $i++) {
                    if ($columns[$i]['start'] >= $sStart) {
                        $startIdx = $i;
                        break;
                    }
                }
            }
            if ($endIdx === null) {
                for ($i = $colCount - 1; $i >= 0; $i--) {
                    if ($columns[$i]['end'] <= $sEnd) {
                        $endIdx = $i;
                        break;
                    }
                }
            }
            if ($startIdx === null || $endIdx === null || $endIdx < $startIdx) {
                continue;
            }
            $placements[] = ['start' => $startIdx, 'span' => $endIdx - $startIdx + 1, 'session' => $s];
        }
        usort($placements, static function (array $a, array $b): int {
            if ($a['start'] !== $b['start']) {
                return $a['start'] <=> $b['start'];
            }
            return $b['span'] <=> $a['span'];
        });

        // Group concurrent sessions that share the same start+span into one cell stack
        $groups = [];
        foreach ($placements as $p) {
            $key = $p['start'] . ':' . $p['span'];
            if (!isset($groups[$key])) {
                $groups[$key] = ['start' => $p['start'], 'span' => $p['span'], 'sessions' => []];
            }
            $groups[$key]['sessions'][] = $p['session'];
        }
        $groups = array_values($groups);
        usort($groups, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        $renderSessionBlock = static function (array $s) use ($typeDot, $editable): string {
            $title = htmlspecialchars(
                trim(($s['code_ecue'] ?? '') . ' ' . ($s['description_ecue'] ?? '')),
                ENT_QUOTES,
                'UTF-8'
            );
            $teacher = htmlspecialchars(trim(($s['ens_prenom'] ?? '') . ' ' . ($s['ens_nom'] ?? '')), ENT_QUOTES, 'UTF-8');
            $room = htmlspecialchars((string) ($s['ressource_nom'] ?? $s['salle'] ?? ''), ENT_QUOTES, 'UTF-8');
            $group = htmlspecialchars(trim(($s['type_groupe'] ?? '') . ' ' . ($s['numero'] ?? '')), ENT_QUOTES, 'UTF-8');
            $type = (string) ($s['type_seance'] ?? '');
            $time = htmlspecialchars(
                substr((string) $s['heure_debut'], 0, 5) . '–' . substr((string) $s['heure_fin'], 0, 5),
                ENT_QUOTES,
                'UTF-8'
            );
            $cell = '<div class="emploi-tt-cell">'
                . $typeDot($type)
                . '<span class="emploi-tt-code">' . htmlspecialchars((string) ($s['code_ecue'] ?? ''), ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</span>'
                . '<span class="emploi-tt-meta" title="' . $title . '">' . $title . '</span>'
                . '<span class="emploi-tt-sub">' . $teacher . '</span>'
                . '<span class="emploi-tt-sub">' . $group . ($room !== '' ? ' · ' . $room : '') . '</span>'
                . '<span class="emploi-tt-sub">' . $time . (!empty($s['locked']) ? ' · verrouillé' : '') . '</span>';
            if ($editable && !empty($s['id_session'])) {
                $cell .= '<details class="emploi-tt-edit"><summary>Modifier</summary></details>';
            }
            return $cell . '</div>';
        };

        while ($colIndex < $colCount) {
            if (!empty($used[$colIndex])) {
                $colIndex++;
                continue;
            }
            $placement = null;
            foreach ($groups as $g) {
                if ($g['start'] === $colIndex) {
                    $placement = $g;
                    break;
                }
            }
            // If a longer block starts later but a shorter one was skipped due to overlap, still show at this index if free
            if ($placement === null) {
                foreach ($groups as $g) {
                    if ($g['start'] < $colIndex && ($g['start'] + $g['span']) > $colIndex) {
                        // covered by earlier colspan
                        break;
                    }
                }
            }
            if ($placement && empty($used[$colIndex])) {
                $span = (int) $placement['span'];
                // Shrink span if later columns already used
                for ($k = 0; $k < $span; $k++) {
                    if (!empty($used[$colIndex + $k])) {
                        $span = $k;
                        break;
                    }
                }
                if ($span < 1) {
                    $html .= '<td class="emploi-tt-empty">&nbsp;</td>';
                    $colIndex++;
                    continue;
                }
                for ($k = 0; $k < $span; $k++) {
                    $used[$colIndex + $k] = true;
                }
                $blocks = '';
                foreach ($placement['sessions'] as $s) {
                    $blocks .= $renderSessionBlock($s);
                }
                $html .= '<td class="emploi-tt-slot" colspan="' . $span . '">' . $blocks . '</td>';
                $colIndex += $span;
            } else {
                $html .= '<td class="emploi-tt-empty">&nbsp;</td>';
                $colIndex++;
            }
        }
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div></div>';
    return $html;
}
