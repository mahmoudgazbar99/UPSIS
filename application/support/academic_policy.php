<?php
declare(strict_types=1);

/**
 * Catalogs and policies that replace school-specific constants.
 * Defaults reproduce the current nursing-school installation.
 * Published rows are versioned: a save retires the previous published row
 * and does not rewrite archives.
 */

function academic_policy_ensure(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    try {
        $pdo = db();
    } catch (Throwable $exception) {
        return;
    }
    institution_ensure();
    academic_policy_tables($pdo);
    academic_policy_widen_columns($pdo);
    academic_policy_seed($pdo);
    academic_policy_audit_category($pdo);
}

function academic_policy_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS schema_migration (
          version VARCHAR(80) NOT NULL,
          applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS program (
          id_program INT UNSIGNED NOT NULL AUTO_INCREMENT,
          code VARCHAR(30) NOT NULL,
          nom VARCHAR(180) NOT NULL,
          nom_ar VARCHAR(180) NOT NULL DEFAULT '',
          award_title VARCHAR(180) NOT NULL,
          award_title_ar VARCHAR(180) NOT NULL DEFAULT '',
          active TINYINT(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (id_program),
          UNIQUE KEY uq_program_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS study_level (
          id_level INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_program INT UNSIGNED DEFAULT NULL,
          code VARCHAR(20) NOT NULL,
          label VARCHAR(80) NOT NULL,
          label_ar VARCHAR(80) NOT NULL DEFAULT '',
          sequence INT NOT NULL,
          is_final TINYINT(1) NOT NULL DEFAULT 0,
          period_codes VARCHAR(120) NOT NULL DEFAULT '',
          active TINYINT(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (id_level),
          UNIQUE KEY uq_level_program_code (id_program, code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_year (
          id_year INT UNSIGNED NOT NULL AUTO_INCREMENT,
          label VARCHAR(20) NOT NULL,
          date_debut DATE DEFAULT NULL,
          date_fin DATE DEFAULT NULL,
          status ENUM('draft','active','closed') NOT NULL DEFAULT 'active',
          PRIMARY KEY (id_year),
          UNIQUE KEY uq_academic_year_label (label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_period (
          id_period INT UNSIGNED NOT NULL AUTO_INCREMENT,
          code VARCHAR(20) NOT NULL,
          label VARCHAR(80) NOT NULL,
          sequence INT NOT NULL,
          period_type VARCHAR(20) NOT NULL DEFAULT 'semester',
          active TINYINT(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (id_period),
          UNIQUE KEY uq_academic_period_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fee_plan (
          id_fee INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_program INT UNSIGNED DEFAULT NULL,
          label VARCHAR(120) NOT NULL,
          total_amount DECIMAL(12,2) NOT NULL,
          currency_code VARCHAR(8) NOT NULL DEFAULT 'DT',
          status ENUM('draft','published','retired') NOT NULL DEFAULT 'published',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_fee),
          KEY idx_fee_status (status, id_program)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS progression_policy (
          id_policy INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_program INT UNSIGNED DEFAULT NULL,
          pass_threshold DECIMAL(5,2) NOT NULL DEFAULT 10.00,
          graduate_status VARCHAR(40) NOT NULL DEFAULT 'diplome',
          status ENUM('draft','published','retired') NOT NULL DEFAULT 'published',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_policy),
          KEY idx_progression_status (status, id_program)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attendance_policy (
          id_policy INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_program INT UNSIGNED DEFAULT NULL,
          max_absences_subject INT UNSIGNED NOT NULL DEFAULT 4,
          missed_percent DECIMAL(5,2) NOT NULL DEFAULT 10.00,
          expected_hours DECIMAL(8,2) NOT NULL DEFAULT 444.00,
          session_hours DECIMAL(5,2) NOT NULL DEFAULT 1.50,
          semester_code VARCHAR(20) NOT NULL DEFAULT 'S1',
          status ENUM('draft','published','retired') NOT NULL DEFAULT 'published',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_policy)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS identity_document_type (
          id_type INT UNSIGNED NOT NULL AUTO_INCREMENT,
          code VARCHAR(20) NOT NULL,
          label VARCHAR(80) NOT NULL,
          charset_kind VARCHAR(12) NOT NULL DEFAULT 'digits',
          min_length INT UNSIGNED NOT NULL DEFAULT 8,
          max_length INT UNSIGNED NOT NULL DEFAULT 8,
          audience VARCHAR(20) NOT NULL DEFAULT 'both',
          active TINYINT(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (id_type),
          UNIQUE KEY uq_identity_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (db_table_exists($pdo, 'specialite') && !$pdo->query("SHOW COLUMNS FROM specialite LIKE 'id_program'")->fetch()) {
        $pdo->exec('ALTER TABLE specialite ADD COLUMN id_program INT UNSIGNED DEFAULT NULL AFTER nom_spec_ar');
    }
    if (db_table_exists($pdo, 'specialite') && !$pdo->query("SHOW COLUMNS FROM specialite LIKE 'active'")->fetch()) {
        $pdo->exec('ALTER TABLE specialite ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1');
    }
    if (db_table_exists($pdo, 'etudiant_archive') && !$pdo->query("SHOW COLUMNS FROM etudiant_archive LIKE 'frais_reference'")->fetch()) {
        $pdo->exec('ALTER TABLE etudiant_archive ADD COLUMN frais_reference DECIMAL(12,2) DEFAULT NULL AFTER finance_total_paye');
    }
}

function academic_policy_widen_columns(PDO $pdo): void
{
    $columns = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND DATA_TYPE = 'enum'
           AND (COLUMN_NAME = 'semestre' OR (TABLE_NAME = 'etudiant' AND COLUMN_NAME IN ('niveau', 'statut')))"
    )->fetchAll();
    foreach ($columns as $column) {
        $table = (string) $column['TABLE_NAME'];
        $name = (string) $column['COLUMN_NAME'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table . $name)) {
            continue;
        }
        $null = ($column['IS_NULLABLE'] ?? '') === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $column['COLUMN_DEFAULT'];
        $defaultSql = '';
        if ($default !== null && $default !== '') {
            $defaultSql = ' DEFAULT ' . $pdo->quote((string) $default);
        }
        $pdo->exec('ALTER TABLE `' . $table . '` MODIFY `' . $name . '` VARCHAR(20) ' . $null . $defaultSql);
    }
    $checks = $pdo->query(
        "SELECT cc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
         FROM information_schema.CHECK_CONSTRAINTS cc
         JOIN information_schema.TABLE_CONSTRAINTS tc
           ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA
          AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
         WHERE cc.CONSTRAINT_SCHEMA = DATABASE()
           AND tc.TABLE_NAME = 'finance_solde'"
    )->fetchAll();
    foreach ($checks as $check) {
        $clause = (string) ($check['CHECK_CLAUSE'] ?? '');
        if (!str_contains($clause, '9800')) {
            continue;
        }
        $name = (string) $check['CONSTRAINT_NAME'];
        if (preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            $pdo->exec('ALTER TABLE finance_solde DROP CHECK `' . $name . '`');
        }
    }
    $solde = $pdo->query("SHOW COLUMNS FROM finance_solde LIKE 'total_paye'")->fetch();
    $type = strtolower((string) ($solde['Type'] ?? ''));
    if ($type !== '' && !str_contains($type, 'decimal(12,2)')) {
        $pdo->exec('ALTER TABLE finance_solde MODIFY total_paye DECIMAL(12,2) NOT NULL DEFAULT 0');
    }
}

function academic_policy_seed(PDO $pdo): void
{
    $insert = $pdo->prepare('INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ([
        'institution_city' => 'Gabès',
        'institution_city_ar' => 'قابس',
        'institution_address' => '',
        'institution_country' => 'Tunisie',
        'institution_phone' => '',
        'institution_email' => '',
        'institution_website' => '',
        'active_period_code' => 'S1',
        'currency_code' => 'DT',
    ] as $key => $value) {
        $insert->execute([$key, $value]);
    }
    $pdo->exec(
        "INSERT INTO program (code, nom, nom_ar, award_title, award_title_ar, active)
         SELECT 'LSI', 'Licence en sciences infirmières', 'الإجازة في علوم التمريض',
                'Licence en sciences infirmières', 'الإجازة في علوم التمريض', 1
         WHERE NOT EXISTS (SELECT 1 FROM program WHERE code = 'LSI')"
    );
    $programId = (int) $pdo->query("SELECT id_program FROM program WHERE code = 'LSI' LIMIT 1")->fetchColumn();
    if ($programId > 0 && db_table_exists($pdo, 'specialite')) {
        $pdo->prepare('UPDATE specialite SET id_program = ? WHERE id_program IS NULL')->execute([$programId]);
    }
    $levels = [
        ['1', '1er', 'السنة الأولى', 1, 0, 'S1,S2'],
        ['2', '2ème', 'السنة الثانية', 2, 0, 'S3,S4'],
        ['3', '3ème', 'السنة الثالثة', 3, 1, 'S5,S6'],
    ];
    $levelInsert = $pdo->prepare(
        'INSERT INTO study_level (id_program, code, label, label_ar, sequence, is_final, period_codes, active)
         SELECT ?, ?, ?, ?, ?, ?, ?, 1 FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM study_level WHERE id_program <=> ? AND code = ?)'
    );
    foreach ($levels as $level) {
        $levelInsert->execute([$programId, $level[0], $level[1], $level[2], $level[3], $level[4], $level[5], $programId, $level[0]]);
    }
    for ($index = 1; $index <= 6; $index++) {
        $code = 'S' . $index;
        $pdo->prepare(
            'INSERT INTO academic_period (code, label, sequence, period_type, active)
             SELECT ?, ?, ?, "semester", 1 FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM academic_period WHERE code = ?)'
        )->execute([$code, 'Semestre ' . $index, $index, $code]);
    }
    $year = ((int) date('Y') - 1) . '-' . date('Y');
    $pdo->prepare(
        'INSERT INTO academic_year (label, status) SELECT ?, "active" FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM academic_year WHERE label = ?)'
    )->execute([$year, $year]);
    $pdo->exec(
        "INSERT INTO fee_plan (id_program, label, total_amount, currency_code, status)
         SELECT NULL, 'Frais de scolarité', 9800.00, 'DT', 'published' FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM fee_plan WHERE status = 'published' AND id_program IS NULL)"
    );
    $pdo->exec(
        "INSERT INTO progression_policy (id_program, pass_threshold, graduate_status, status)
         SELECT NULL, 10.00, 'diplome', 'published' FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM progression_policy WHERE status = 'published' AND id_program IS NULL)"
    );
    $pdo->exec(
        "INSERT INTO attendance_policy (id_program, max_absences_subject, missed_percent, expected_hours, session_hours, semester_code, status)
         SELECT NULL, 4, 10.00, 444.00, 1.50, 'S1', 'published' FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM attendance_policy WHERE status = 'published' AND id_program IS NULL)"
    );
    $documents = [
        ['cin', 'CIN', 'digits', 8, 8, 'both'],
        ['passeport', 'Passeport', 'alnum', 7, 7, 'etudiant'],
    ];
    $documentInsert = $pdo->prepare(
        'INSERT INTO identity_document_type (code, label, charset_kind, min_length, max_length, audience, active)
         SELECT ?, ?, ?, ?, ?, ?, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM identity_document_type WHERE code = ?)'
    );
    foreach ($documents as $document) {
        $documentInsert->execute([$document[0], $document[1], $document[2], $document[3], $document[4], $document[5], $document[0]]);
    }
    $pdo->exec("INSERT IGNORE INTO schema_migration (version) VALUES ('2026_10_14_academic_policy_v1')");
}

function academic_policy_audit_category(PDO $pdo): void
{
    if (!db_table_exists($pdo, 'audit_log')) {
        return;
    }
    $column = $pdo->query("SHOW COLUMNS FROM audit_log LIKE 'category'")->fetch();
    $type = (string) ($column['Type'] ?? '');
    if ($type !== '' && str_contains(strtolower($type), 'enum') && !str_contains($type, 'reglage')) {
        $pdo->exec("ALTER TABLE audit_log MODIFY category ENUM('note','paiement','presence','compte','reglage') NOT NULL");
    }
}

function academic_policy_note(string $action, string $summary): void
{
    audit_record('reglage', $action, $summary);
}

function institution_city(): string
{
    academic_policy_ensure();
    return trim(institution_setting('institution_city')) ?: 'Gabès';
}

function institution_city_ar(): string
{
    academic_policy_ensure();
    return trim(institution_setting('institution_city_ar')) ?: 'قابس';
}

function institution_country(): string
{
    academic_policy_ensure();
    return trim(institution_setting('institution_country')) ?: 'Tunisie';
}

function institution_address(): string
{
    academic_policy_ensure();
    return trim(institution_setting('institution_address'));
}

function institution_phone(): string
{
    academic_policy_ensure();
    return trim(institution_setting('institution_phone'));
}

function institution_email(): string
{
    academic_policy_ensure();
    return trim(institution_setting('institution_email'));
}

function institution_website(): string
{
    academic_policy_ensure();
    return trim(institution_setting('institution_website'));
}

function institution_place_fr(?string $date = null): string
{
    $date = $date ?: date('d/m/Y');
    return institution_city() . ', le ' . $date;
}

function institution_place_ar(string $date): string
{
    return institution_city_ar() . ' في، ' . $date;
}

function institution_currency(): string
{
    academic_policy_ensure();
    $code = trim(institution_setting('currency_code'));
    return $code !== '' ? $code : 'DT';
}

function institution_money(float $amount): string
{
    return number_format($amount, 2, ',', ' ') . ' ' . institution_currency();
}

function institution_save_place(string $city, string $cityAr, string $address, string $country): void
{
    $city = trim(preg_replace('/\s+/u', ' ', $city) ?? '');
    $cityAr = trim(preg_replace('/\s+/u', ' ', $cityAr) ?? '');
    $country = trim(preg_replace('/\s+/u', ' ', $country) ?? '');
    $address = trim($address);
    if ($city === '' || mb_strlen($city) > 80) {
        throw new InvalidArgumentException('Saisissez la ville d’émission (80 caractères maximum).');
    }
    if ($cityAr === '' || mb_strlen($cityAr) > 80 || !preg_match('/\p{Arabic}/u', $cityAr)) {
        throw new InvalidArgumentException('Saisissez la ville en arabe.');
    }
    if ($country === '' || mb_strlen($country) > 80) {
        throw new InvalidArgumentException('Saisissez le pays.');
    }
    if (mb_strlen($address) > 255) {
        throw new InvalidArgumentException('L’adresse est trop longue.');
    }
    institution_setting_put('institution_city', $city);
    institution_setting_put('institution_city_ar', $cityAr);
    institution_setting_put('institution_country', $country);
    institution_setting_put('institution_address', $address);
    academic_policy_note('lieu', 'Ville ' . $city . ' / ' . $cityAr . ' · ' . $country);
}

function institution_save_contact(string $phone, string $email, string $website): void
{
    $phone = trim(preg_replace('/\s+/u', ' ', $phone) ?? '');
    $email = trim($email);
    $website = trim($website);
    if (mb_strlen($phone) > 40) {
        throw new InvalidArgumentException('Le téléphone est trop long (40 caractères maximum).');
    }
    if ($phone !== '' && !preg_match('/^[0-9+\s().\-]{6,40}$/u', $phone)) {
        throw new InvalidArgumentException('Saisissez un numéro de téléphone valide.');
    }
    if (mb_strlen($email) > 120) {
        throw new InvalidArgumentException('L’e-mail est trop long.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Saisissez une adresse e-mail valide.');
    }
    if (mb_strlen($website) > 180) {
        throw new InvalidArgumentException('Le site web est trop long.');
    }
    if ($website !== '') {
        if (!preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        if (!filter_var($website, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Saisissez une URL de site web valide.');
        }
    }
    institution_setting_put('institution_phone', $phone);
    institution_setting_put('institution_email', $email);
    institution_setting_put('institution_website', $website);
    academic_policy_note(
        'contact',
        'Contact ' . ($phone !== '' ? $phone : '—') . ' · ' . ($email !== '' ? $email : '—') . ' · ' . ($website !== '' ? $website : '—')
    );
}

function default_program_id(): int
{
    academic_policy_ensure();
    try {
        return (int) db()->query("SELECT id_program FROM program WHERE code = 'LSI' ORDER BY id_program LIMIT 1")->fetchColumn();
    } catch (Throwable $exception) {
        return 0;
    }
}

function institution_award_title(?int $programId = null): string
{
    $program = academic_program($programId ?: default_program_id());
    return (string) ($program['award_title'] ?? 'Licence en sciences infirmières');
}

function institution_award_title_ar(?int $programId = null): string
{
    $program = academic_program($programId ?: default_program_id());
    return (string) ($program['award_title_ar'] ?? 'الإجازة في علوم التمريض');
}

function academic_program(int $programId): ?array
{
    academic_policy_ensure();
    if ($programId < 1) {
        return null;
    }
    $query = db()->prepare('SELECT * FROM program WHERE id_program = ?');
    $query->execute([$programId]);
    $row = $query->fetch();
    return $row ?: null;
}

function academic_programs(): array
{
    academic_policy_ensure();
    return db()->query('SELECT * FROM program ORDER BY active DESC, nom')->fetchAll();
}

function academic_periods(): array
{
    academic_policy_ensure();
    try {
        $rows = db()->query('SELECT * FROM academic_period WHERE active = 1 ORDER BY sequence, code')->fetchAll();
    } catch (Throwable $exception) {
        $rows = [];
    }
    if ($rows) {
        return $rows;
    }
    $fallback = [];
    for ($index = 1; $index <= 6; $index++) {
        $fallback[] = ['code' => 'S' . $index, 'label' => 'Semestre ' . $index, 'sequence' => $index, 'period_type' => 'semester'];
    }
    return $fallback;
}

function academic_period_codes(): array
{
    return array_map(static fn(array $period): string => (string) $period['code'], academic_periods());
}

function academic_period_valid(string $code): bool
{
    return in_array($code, academic_period_codes(), true);
}

function academic_years(): array
{
    academic_policy_ensure();
    try {
        return db()->query(
            'SELECT * FROM academic_year ORDER BY label DESC, id_year DESC'
        )->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
}

function academic_year_active(): ?array
{
    academic_policy_ensure();
    try {
        $row = db()->query(
            "SELECT * FROM academic_year WHERE status = 'active' ORDER BY label DESC, id_year DESC LIMIT 1"
        )->fetch();
        if ($row) {
            return $row;
        }
        $fallback = db()->query(
            'SELECT * FROM academic_year ORDER BY label DESC, id_year DESC LIMIT 1'
        )->fetch();
        return $fallback ?: null;
    } catch (Throwable $exception) {
        return null;
    }
}

function academic_active_year_label(): string
{
    $row = academic_year_active();
    if ($row && trim((string) ($row['label'] ?? '')) !== '') {
        return (string) $row['label'];
    }
    return ((int) date('Y') - 1) . '-' . date('Y');
}

function academic_active_period_code(): string
{
    academic_policy_ensure();
    $code = strtoupper(trim(institution_setting('active_period_code')));
    if ($code !== '' && academic_period_valid($code)) {
        return $code;
    }
    $periods = academic_periods();
    return $periods ? strtoupper((string) $periods[0]['code']) : 'S1';
}

function academic_active_period(): ?array
{
    $code = academic_active_period_code();
    foreach (academic_periods() as $period) {
        if (strtoupper((string) $period['code']) === $code) {
            return $period;
        }
    }
    return null;
}

function academic_add_year(string $label, string $dateDebut = '', string $dateFin = ''): int
{
    academic_policy_ensure();
    $label = trim(preg_replace('/\s+/u', '', $label) ?? '');
    if (!preg_match('/^\d{4}-\d{4}$/', $label)) {
        throw new InvalidArgumentException('L’année doit être au format AAAA-AAAA (ex. 2025-2026).');
    }
    [$start, $end] = array_map('intval', explode('-', $label));
    if ($end !== $start + 1) {
        throw new InvalidArgumentException('La seconde année doit être la suivante (ex. 2025-2026).');
    }
    $dateDebut = trim($dateDebut);
    $dateFin = trim($dateFin);
    if ($dateDebut !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDebut)) {
        throw new InvalidArgumentException('Date de début invalide.');
    }
    if ($dateFin !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFin)) {
        throw new InvalidArgumentException('Date de fin invalide.');
    }
    if ($dateDebut !== '' && $dateFin !== '' && $dateFin < $dateDebut) {
        throw new InvalidArgumentException('La date de fin doit être après la date de début.');
    }
    $exists = db()->prepare('SELECT id_year FROM academic_year WHERE label = ? LIMIT 1');
    $exists->execute([$label]);
    if ($exists->fetchColumn()) {
        throw new InvalidArgumentException('Cette année scolaire existe déjà.');
    }
    db()->prepare(
        'INSERT INTO academic_year (label, date_debut, date_fin, status) VALUES (?, ?, ?, ?)'
    )->execute([
        $label,
        $dateDebut !== '' ? $dateDebut : null,
        $dateFin !== '' ? $dateFin : null,
        'draft',
    ]);
    $id = (int) db()->lastInsertId();
    academic_policy_note('annee', 'Année ' . $label . ' créée');
    return $id;
}

function academic_set_active_year(int $yearId): void
{
    academic_policy_ensure();
    if ($yearId < 1) {
        throw new InvalidArgumentException('Année invalide.');
    }
    $pdo = db();
    $find = $pdo->prepare('SELECT id_year, label FROM academic_year WHERE id_year = ? LIMIT 1');
    $find->execute([$yearId]);
    $year = $find->fetch();
    if (!$year) {
        throw new InvalidArgumentException('Année introuvable.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE academic_year SET status = 'closed' WHERE status = 'active'");
        $pdo->prepare("UPDATE academic_year SET status = 'active' WHERE id_year = ?")->execute([$yearId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    academic_policy_note('annee', 'Année active : ' . $year['label']);
}

function academic_set_active_period(string $code): void
{
    academic_policy_ensure();
    $code = strtoupper(trim($code));
    if (!academic_period_valid($code)) {
        throw new InvalidArgumentException('Choisissez une période existante dans la structure académique.');
    }
    institution_setting_put('active_period_code', $code);
    academic_policy_note('periode_active', 'Période active : ' . $code);
}

function study_levels(?int $programId = null): array
{
    academic_policy_ensure();
    $programId = $programId ?: default_program_id();
    try {
        $query = db()->prepare('SELECT * FROM study_level WHERE active = 1 AND (id_program = ? OR id_program IS NULL) ORDER BY sequence, code');
        $query->execute([$programId]);
        $rows = $query->fetchAll();
    } catch (Throwable $exception) {
        $rows = [];
    }
    if ($rows) {
        return $rows;
    }
    return [
        ['code' => '1', 'label' => '1er', 'label_ar' => 'السنة الأولى', 'sequence' => 1, 'is_final' => 0, 'period_codes' => 'S1,S2'],
        ['code' => '2', 'label' => '2ème', 'label_ar' => 'السنة الثانية', 'sequence' => 2, 'is_final' => 0, 'period_codes' => 'S3,S4'],
        ['code' => '3', 'label' => '3ème', 'label_ar' => 'السنة الثالثة', 'sequence' => 3, 'is_final' => 1, 'period_codes' => 'S5,S6'],
    ];
}

function study_level_row(string $code, ?int $programId = null): ?array
{
    foreach (study_levels($programId) as $level) {
        if ((string) $level['code'] === $code) {
            return $level;
        }
    }
    return null;
}

function study_level_label(string $code, ?int $programId = null): string
{
    $level = study_level_row($code, $programId);
    return $level ? (string) $level['label'] : $code;
}

function study_level_label_ar(string $code, ?int $programId = null): string
{
    $level = study_level_row($code, $programId);
    if ($level && (string) $level['label_ar'] !== '') {
        return (string) $level['label_ar'];
    }
    return ['1' => 'السنة الأولى', '2' => 'السنة الثانية', '3' => 'السنة الثالثة'][$code] ?? $code;
}

function study_level_periods(string $code, ?int $programId = null): array
{
    $level = study_level_row($code, $programId);
    $raw = (string) ($level['period_codes'] ?? '');
    $codes = array_values(array_filter(array_map('trim', explode(',', $raw))));
    return $codes;
}

function published_policy(string $table, ?int $programId = null): ?array
{
    academic_policy_ensure();
    $allowed = ['fee_plan', 'progression_policy', 'attendance_policy'];
    if (!in_array($table, $allowed, true)) {
        return null;
    }
    if ($programId) {
        try {
            $query = db()->prepare('SELECT * FROM ' . $table . ' WHERE status = "published" AND id_program = ? ORDER BY created_at DESC LIMIT 1');
            $query->execute([$programId]);
            $row = $query->fetch();
            if ($row) {
                return $row;
            }
        } catch (Throwable $exception) {
            return null;
        }
    }
    try {
        $query = db()->query('SELECT * FROM ' . $table . ' WHERE status = "published" AND id_program IS NULL ORDER BY created_at DESC LIMIT 1');
        $row = $query->fetch();
        return $row ?: null;
    } catch (Throwable $exception) {
        return null;
    }
}

function institution_fee_amount(?int $programId = null): float
{
    $plan = published_policy('fee_plan', $programId);
    if ($plan) {
        return (float) $plan['total_amount'];
    }
    return 9800.0;
}

function progression_policy(?int $programId = null): array
{
    $policy = published_policy('progression_policy', $programId);
    return $policy ?: ['id_policy' => 0, 'pass_threshold' => 10, 'graduate_status' => 'diplome', 'status' => 'published'];
}

function progression_plan(string $levelCode, ?float $average, ?int $programId = null): array
{
    $policy = progression_policy($programId);
    $threshold = (float) $policy['pass_threshold'];
    $admitted = $average !== null && $average >= $threshold;
    $levels = study_levels($programId);
    $current = study_level_row($levelCode, $programId);
    $next = $levelCode;
    $statut = 'actif';
    if ($admitted && $current) {
        if ((int) $current['is_final'] === 1) {
            $statut = (string) $policy['graduate_status'];
        } else {
            $target = (int) $current['sequence'] + 1;
            foreach ($levels as $level) {
                if ((int) $level['sequence'] === $target) {
                    $next = (string) $level['code'];
                    if ((int) $level['is_final'] === 1) {
                        $statut = (string) $policy['graduate_status'];
                    }
                    break;
                }
            }
        }
    }
    return [
        'decision' => $admitted ? 'admis' : 'redouble',
        'next_level' => $next,
        'statut' => $statut,
        'threshold' => $threshold,
        'policy_id' => (int) ($policy['id_policy'] ?? 0),
    ];
}

function attendance_policy(?int $programId = null): array
{
    $policy = published_policy('attendance_policy', $programId);
    return $policy ?: [
        'max_absences_subject' => 4,
        'missed_percent' => 10,
        'expected_hours' => 444,
        'session_hours' => 1.5,
        'semester_code' => 'S1',
    ];
}

function identity_rule(string $code): array
{
    academic_policy_ensure();
    $fallback = [
        'cin' => ['code' => 'cin', 'label' => 'CIN', 'charset_kind' => 'digits', 'min_length' => 8, 'max_length' => 8],
        'passeport' => ['code' => 'passeport', 'label' => 'Passeport', 'charset_kind' => 'alnum', 'min_length' => 7, 'max_length' => 7],
    ];
    try {
        $query = db()->prepare('SELECT * FROM identity_document_type WHERE code = ? AND active = 1');
        $query->execute([$code]);
        $row = $query->fetch();
        if ($row) {
            return $row;
        }
    } catch (Throwable $exception) {
        /* Use the built-in rule. */
    }
    return $fallback[$code] ?? $fallback['cin'];
}

function identity_pattern(string $code): string
{
    $rule = identity_rule($code);
    $min = max(1, (int) $rule['min_length']);
    $max = max($min, (int) $rule['max_length']);
    $class = ($rule['charset_kind'] ?? '') === 'alnum' ? '[A-Za-z0-9]' : '[0-9]';
    if ($min === $max) {
        return $class . '{' . $min . '}';
    }
    return $class . '{' . $min . ',' . $max . '}';
}

function identity_matches(string $code, string $value): bool
{
    return preg_match('/^' . identity_pattern($code) . '$/', $value) === 1;
}

function identity_error(string $code): string
{
    $rule = identity_rule($code);
    $kind = ($rule['charset_kind'] ?? '') === 'alnum' ? 'caractères alphanumériques' : 'chiffres';
    $min = (int) $rule['min_length'];
    $max = (int) $rule['max_length'];
    $length = $min === $max ? (string) $min : $min . ' à ' . $max;
    return (string) $rule['label'] . ' : ' . $length . ' ' . $kind . '.';
}

function academic_publish_replace(string $table, ?int $programId, array $columns): void
{
    $allowed = ['fee_plan', 'progression_policy', 'attendance_policy'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Politique inconnue.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $retire = $pdo->prepare('UPDATE ' . $table . ' SET status = "retired" WHERE status = "published" AND id_program <=> ?');
        $retire->execute([$programId]);
        $columns['id_program'] = $programId;
        $columns['status'] = 'published';
        $names = array_keys($columns);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')';
        $pdo->prepare($sql)->execute(array_values($columns));
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function academic_add_period(string $code, string $label, int $sequence, string $type): void
{
    academic_policy_ensure();
    $code = strtoupper(trim($code));
    $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
    $type = trim($type);
    if (!preg_match('/^[A-Z0-9]{1,12}$/', $code)) {
        throw new InvalidArgumentException('Le code de période contient 1 à 12 lettres ou chiffres.');
    }
    if ($label === '' || mb_strlen($label) > 80) {
        throw new InvalidArgumentException('Saisissez le libellé de la période.');
    }
    if (!in_array($type, ['semester', 'trimester', 'quarter', 'custom'], true)) {
        throw new InvalidArgumentException('Choisissez le type de période.');
    }
    if ($sequence < 1 || $sequence > 40) {
        throw new InvalidArgumentException('L’ordre doit être entre 1 et 40.');
    }
    $exists = db()->prepare('SELECT 1 FROM academic_period WHERE code = ?');
    $exists->execute([$code]);
    if ($exists->fetchColumn()) {
        throw new InvalidArgumentException('Cette période existe déjà.');
    }
    db()->prepare('INSERT INTO academic_period (code, label, sequence, period_type, active) VALUES (?, ?, ?, ?, 1)')
        ->execute([$code, $label, $sequence, $type]);
    academic_policy_note('periode', 'Période ' . $code . ' ajoutée');
}

function academic_add_level(int $programId, string $code, string $label, string $labelAr, int $sequence, bool $final, string $periods): void
{
    academic_policy_ensure();
    $code = trim($code);
    $label = trim($label);
    $labelAr = trim($labelAr);
    $periods = strtoupper(str_replace(' ', '', $periods));
    if (!preg_match('/^[A-Za-z0-9]{1,12}$/', $code)) {
        throw new InvalidArgumentException('Le code du niveau est invalide.');
    }
    if ($label === '' || $labelAr === '' || !preg_match('/\p{Arabic}/u', $labelAr)) {
        throw new InvalidArgumentException('Saisissez le libellé français et arabe du niveau.');
    }
    $known = academic_period_codes();
    foreach (array_filter(explode(',', $periods)) as $period) {
        if (!in_array($period, $known, true)) {
            throw new InvalidArgumentException('Période inconnue : ' . $period);
        }
    }
    if ($final) {
        db()->prepare('UPDATE study_level SET is_final = 0 WHERE id_program <=> ?')->execute([$programId > 0 ? $programId : null]);
    }
    db()->prepare(
        'INSERT INTO study_level (id_program, code, label, label_ar, sequence, is_final, period_codes, active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([$programId > 0 ? $programId : null, $code, $label, $labelAr, $sequence, $final ? 1 : 0, $periods]);
    academic_policy_note('niveau', 'Niveau ' . $code . ' ajouté');
}

function academic_add_program(string $code, string $name, string $nameAr, string $award, string $awardAr): int
{
    academic_policy_ensure();
    $code = strtoupper(trim($code));
    $name = trim($name);
    $nameAr = trim($nameAr);
    $award = trim($award);
    $awardAr = trim($awardAr);
    if (!preg_match('/^[A-Z0-9]{2,12}$/', $code) || $name === '' || $award === '' || $awardAr === '' || !preg_match('/\p{Arabic}/u', $awardAr)) {
        throw new InvalidArgumentException('Code, nom et intitulé du diplôme (arabe compris) sont requis.');
    }
    db()->prepare('INSERT INTO program (code, nom, nom_ar, award_title, award_title_ar, active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$code, $name, $nameAr, $award, $awardAr]);
    academic_policy_note('programme', 'Programme ' . $code . ' créé');
    return (int) db()->lastInsertId();
}

function institution_personalize_docx(string $sourcePath): string
{
    $copy = tempnam(sys_get_temp_dir(), 'brand-docx');
    if ($copy === false || !copy($sourcePath, $copy)) {
        return $sourcePath;
    }
    $zip = new ZipArchive();
    if ($zip->open($copy) !== true) {
        return $sourcePath;
    }
    $xml = $zip->getFromName('word/document.xml');
    if (is_string($xml)) {
        $xml = str_replace(
            ['Licence en sciences infirmières', 'Université privée des sciences infirmières et de santé de Gabès', 'Gabès, le'],
            ['${diplome}', '${universite}', institution_city() . ', le'],
            $xml
        );
        $xml = str_replace(
            '<w:tblpPr w:leftFromText="141" w:rightFromText="141" w:vertAnchor="text" w:horzAnchor="margin" w:tblpY="-126"/>',
            '',
            $xml
        );
        $zip->addFromString('word/document.xml', $xml);
    }
    $zip->close();
    return $copy;
}
