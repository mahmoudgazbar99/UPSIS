<?php
declare(strict_types=1);

/**
 * Admissions: campaigns → pre-registration → candidate portal → tests/interviews
 * → committee → final enrollment. Reuses etudiant / etudiant_compte / specialite /
 * academic_year. Official student number stays INS###/YYYY (same as inscription.php).
 * Application refs are new: CAND-YYYY-NNNNN. Certificate prefixes untouched.
 */

function admissions_ensure(): void
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
    admissions_tables($pdo);
    admissions_seed($pdo);
    admissions_audit_category($pdo);
    $pdo->prepare('INSERT IGNORE INTO schema_migration (version) VALUES (?)')->execute(['2026_11_23_admissions_v1']);
    $pdo->prepare('INSERT IGNORE INTO schema_migration (version) VALUES (?)')->execute(['2026_11_24_admissions_official_v2']);
}

function admissions_audit_category(PDO $pdo): void
{
    if (!db_table_exists($pdo, 'audit_log')) {
        return;
    }
    try {
        $column = $pdo->query("SHOW COLUMNS FROM audit_log LIKE 'category'")->fetch();
        $type = (string) ($column['Type'] ?? '');
        if ($type !== '' && str_contains(strtolower($type), 'enum') && !str_contains($type, 'admission')) {
            $pdo->exec("ALTER TABLE audit_log MODIFY category ENUM('note','paiement','presence','compte','reglage','stage','cours','admission') NOT NULL");
        }
    } catch (Throwable $ignored) {
        /* Postgres / already widened */
    }
}

function admissions_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_campaign (
          id_campaign INT UNSIGNED NOT NULL AUTO_INCREMENT,
          label VARCHAR(160) NOT NULL,
          academic_year_label VARCHAR(20) NOT NULL,
          id_spec INT UNSIGNED DEFAULT NULL,
          id_program INT UNSIGNED DEFAULT NULL,
          opens_at DATE DEFAULT NULL,
          closes_at DATE DEFAULT NULL,
          offer_days INT UNSIGNED NOT NULL DEFAULT 14,
          capacity INT UNSIGNED NOT NULL DEFAULT 0,
          status ENUM('draft','open','closed','archived') NOT NULL DEFAULT 'draft',
          notes TEXT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_campaign),
          KEY idx_adm_camp_status (status, academic_year_label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_doc_type (
          id_doc_type INT UNSIGNED NOT NULL AUTO_INCREMENT,
          code VARCHAR(40) NOT NULL,
          label VARCHAR(160) NOT NULL,
          required TINYINT(1) NOT NULL DEFAULT 1,
          application_types VARCHAR(120) NOT NULL DEFAULT 'all',
          max_size_kb INT UNSIGNED NOT NULL DEFAULT 4096,
          mime_allow VARCHAR(160) NOT NULL DEFAULT 'pdf,jpg,jpeg,png',
          active TINYINT(1) NOT NULL DEFAULT 1,
          sort_order INT NOT NULL DEFAULT 0,
          PRIMARY KEY (id_doc_type),
          UNIQUE KEY uq_adm_doc_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_candidate (
          id_candidate INT UNSIGNED NOT NULL AUTO_INCREMENT,
          email VARCHAR(160) NOT NULL,
          password VARCHAR(255) NOT NULL DEFAULT '',
          cin_passeport VARCHAR(40) NOT NULL DEFAULT '',
          nom VARCHAR(80) NOT NULL DEFAULT '',
          prenom VARCHAR(80) NOT NULL DEFAULT '',
          nom_ar VARCHAR(80) NOT NULL DEFAULT '',
          prenom_ar VARCHAR(80) NOT NULL DEFAULT '',
          date_naissance DATE DEFAULT NULL,
          lieu_naissance VARCHAR(120) NOT NULL DEFAULT '',
          sexe VARCHAR(10) NOT NULL DEFAULT '',
          nationalite VARCHAR(80) NOT NULL DEFAULT '',
          num_tel VARCHAR(40) NOT NULL DEFAULT '',
          adresse VARCHAR(255) NOT NULL DEFAULT '',
          ville VARCHAR(80) NOT NULL DEFAULT '',
          pays VARCHAR(80) NOT NULL DEFAULT 'Tunisie',
          active TINYINT(1) NOT NULL DEFAULT 1,
          auth_generation INT UNSIGNED NOT NULL DEFAULT 1,
          email_verified TINYINT(1) NOT NULL DEFAULT 0,
          activation_token VARCHAR(64) NOT NULL DEFAULT '',
          activation_expires TIMESTAMP NULL,
          id_etudiant INT UNSIGNED DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_candidate),
          UNIQUE KEY uq_adm_cand_email (email),
          KEY idx_adm_cand_cin (cin_passeport)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_application (
          id_application INT UNSIGNED NOT NULL AUTO_INCREMENT,
          application_number VARCHAR(32) NOT NULL,
          id_candidate INT UNSIGNED NOT NULL,
          id_campaign INT UNSIGNED NOT NULL,
          id_spec INT UNSIGNED DEFAULT NULL,
          application_type VARCHAR(40) NOT NULL DEFAULT 'initial',
          academic_year_label VARCHAR(20) NOT NULL DEFAULT '',
          status VARCHAR(40) NOT NULL DEFAULT 'submitted',
          document_status VARCHAR(40) NOT NULL DEFAULT 'incomplete',
          test_status VARCHAR(40) NOT NULL DEFAULT 'not_started',
          interview_status VARCHAR(40) NOT NULL DEFAULT 'not_started',
          decision_status VARCHAR(40) NOT NULL DEFAULT 'pending',
          payment_status VARCHAR(40) NOT NULL DEFAULT 'not_required',
          enrollment_status VARCHAR(40) NOT NULL DEFAULT 'not_started',
          account_status VARCHAR(40) NOT NULL DEFAULT 'candidate_active',
          annee_bac VARCHAR(10) NOT NULL DEFAULT '',
          specialite_bac VARCHAR(120) NOT NULL DEFAULT '',
          moyenne_bac DECIMAL(5,2) DEFAULT NULL,
          etablissement_origine VARCHAR(180) NOT NULL DEFAULT '',
          diplome_info VARCHAR(255) NOT NULL DEFAULT '',
          etudes_anterieures TEXT NULL,
          notes_candidat TEXT NULL,
          assigned_officer VARCHAR(120) NOT NULL DEFAULT '',
          id_etudiant INT UNSIGNED DEFAULT NULL,
          submitted_at TIMESTAMP NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_application),
          UNIQUE KEY uq_adm_app_number (application_number),
          KEY idx_adm_app_status (status, id_campaign),
          KEY idx_adm_app_candidate (id_candidate)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_application_seq (
          year_label VARCHAR(10) NOT NULL,
          last_number INT UNSIGNED NOT NULL DEFAULT 0,
          PRIMARY KEY (year_label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_document (
          id_document INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_application INT UNSIGNED NOT NULL,
          id_doc_type INT UNSIGNED NOT NULL,
          stored_name VARCHAR(80) NOT NULL DEFAULT '',
          original_name VARCHAR(180) NOT NULL DEFAULT '',
          mime VARCHAR(80) NOT NULL DEFAULT '',
          size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
          status VARCHAR(40) NOT NULL DEFAULT 'uploaded',
          rejection_reason VARCHAR(500) NOT NULL DEFAULT '',
          internal_note VARCHAR(500) NOT NULL DEFAULT '',
          verified_by VARCHAR(120) NOT NULL DEFAULT '',
          verified_at TIMESTAMP NULL,
          uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_document),
          KEY idx_adm_doc_app (id_application, id_doc_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_status_history (
          id_history INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_application INT UNSIGNED NOT NULL,
          field_name VARCHAR(40) NOT NULL DEFAULT 'status',
          previous_status VARCHAR(40) NOT NULL DEFAULT '',
          new_status VARCHAR(40) NOT NULL,
          changed_by_role VARCHAR(40) NOT NULL DEFAULT '',
          changed_by_id INT UNSIGNED DEFAULT NULL,
          changed_by_label VARCHAR(160) NOT NULL DEFAULT '',
          reason VARCHAR(500) NOT NULL DEFAULT '',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_history),
          KEY idx_adm_hist_app (id_application, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_test_session (
          id_session INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_campaign INT UNSIGNED NOT NULL,
          name VARCHAR(160) NOT NULL,
          test_type VARCHAR(60) NOT NULL DEFAULT 'psychometric',
          description TEXT NULL,
          duration_minutes INT UNSIGNED NOT NULL DEFAULT 60,
          session_date DATE NOT NULL,
          session_time TIME NOT NULL,
          location VARCHAR(160) NOT NULL DEFAULT '',
          room VARCHAR(80) NOT NULL DEFAULT '',
          capacity INT UNSIGNED NOT NULL DEFAULT 30,
          instructions TEXT NULL,
          materials VARCHAR(255) NOT NULL DEFAULT '',
          evaluators VARCHAR(255) NOT NULL DEFAULT '',
          status VARCHAR(40) NOT NULL DEFAULT 'scheduled',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_session),
          KEY idx_adm_test_camp (id_campaign, session_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_test_assignment (
          id_assignment INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_session INT UNSIGNED NOT NULL,
          id_application INT UNSIGNED NOT NULL,
          attendance VARCHAR(40) NOT NULL DEFAULT 'scheduled',
          score DECIMAL(6,2) DEFAULT NULL,
          criteria_json TEXT NULL,
          comments TEXT NULL,
          recommendation VARCHAR(80) NOT NULL DEFAULT '',
          evaluator_label VARCHAR(160) NOT NULL DEFAULT '',
          evaluated_at TIMESTAMP NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_assignment),
          UNIQUE KEY uq_adm_test_assign (id_session, id_application),
          KEY idx_adm_test_app (id_application)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_interview (
          id_interview INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_application INT UNSIGNED NOT NULL,
          id_campaign INT UNSIGNED NOT NULL,
          interview_date DATE NOT NULL,
          interview_time TIME NOT NULL,
          location VARCHAR(160) NOT NULL DEFAULT '',
          room VARCHAR(80) NOT NULL DEFAULT '',
          jury_members VARCHAR(255) NOT NULL DEFAULT '',
          status VARCHAR(40) NOT NULL DEFAULT 'scheduled',
          score DECIMAL(6,2) DEFAULT NULL,
          criteria_json TEXT NULL,
          comments TEXT NULL,
          recommendation VARCHAR(80) NOT NULL DEFAULT '',
          evaluated_by VARCHAR(160) NOT NULL DEFAULT '',
          evaluated_at TIMESTAMP NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_interview),
          KEY idx_adm_int_app (id_application)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_decision (
          id_decision INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_application INT UNSIGNED NOT NULL,
          decision VARCHAR(40) NOT NULL,
          decision_date DATE NOT NULL,
          committee_label VARCHAR(255) NOT NULL DEFAULT '',
          comments TEXT NULL,
          reason VARCHAR(500) NOT NULL DEFAULT '',
          published TINYINT(1) NOT NULL DEFAULT 0,
          published_at TIMESTAMP NULL,
          offer_deadline DATE DEFAULT NULL,
          decided_by VARCHAR(160) NOT NULL DEFAULT '',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_decision),
          KEY idx_adm_dec_app (id_application, published)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_payment (
          id_payment INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_application INT UNSIGNED NOT NULL,
          amount DECIMAL(12,2) NOT NULL DEFAULT 0,
          currency_code VARCHAR(8) NOT NULL DEFAULT 'DT',
          payment_date DATE DEFAULT NULL,
          method VARCHAR(60) NOT NULL DEFAULT '',
          reference_no VARCHAR(80) NOT NULL DEFAULT '',
          receipt_stored VARCHAR(80) NOT NULL DEFAULT '',
          status VARCHAR(40) NOT NULL DEFAULT 'unpaid',
          verified_by VARCHAR(120) NOT NULL DEFAULT '',
          verified_at TIMESTAMP NULL,
          notes VARCHAR(500) NOT NULL DEFAULT '',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_payment),
          KEY idx_adm_pay_app (id_application)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_enrollment_checklist (
          id_check INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_application INT UNSIGNED NOT NULL,
          step_code VARCHAR(60) NOT NULL,
          step_label VARCHAR(160) NOT NULL,
          required TINYINT(1) NOT NULL DEFAULT 1,
          completed TINYINT(1) NOT NULL DEFAULT 0,
          completed_at TIMESTAMP NULL,
          completed_by VARCHAR(120) NOT NULL DEFAULT '',
          PRIMARY KEY (id_check),
          UNIQUE KEY uq_adm_check (id_application, step_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_notification (
          id_notification INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_candidate INT UNSIGNED NOT NULL,
          id_application INT UNSIGNED DEFAULT NULL,
          event_code VARCHAR(60) NOT NULL,
          title VARCHAR(180) NOT NULL,
          body VARCHAR(500) NOT NULL DEFAULT '',
          link VARCHAR(180) NOT NULL DEFAULT '',
          is_read TINYINT(1) NOT NULL DEFAULT 0,
          email_status VARCHAR(40) NOT NULL DEFAULT 'skipped',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_notification),
          KEY idx_adm_notif_cand (id_candidate, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admission_setting (
          setting_key VARCHAR(80) NOT NULL,
          setting_value TEXT NOT NULL,
          PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    admissions_migrate_columns($pdo);
}

/** Extend admissions tables for official UPSG inscription (بلاغ + نظام داخلي). */
function admissions_migrate_columns(PDO $pdo): void
{
    $docCols = [
        'requires_legalization' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'channel' => "VARCHAR(20) NOT NULL DEFAULT 'app'",
        'printable_code' => "VARCHAR(40) NOT NULL DEFAULT ''",
        'hint_fr' => "VARCHAR(255) NOT NULL DEFAULT ''",
    ];
    foreach ($docCols as $col => $ddl) {
        try {
            $pdo->exec("ALTER TABLE admission_doc_type ADD COLUMN {$col} {$ddl}");
        } catch (Throwable $ignored) {
            /* already present */
        }
    }
    try {
        $pdo->exec("ALTER TABLE admission_payment ADD COLUMN payment_kind VARCHAR(40) NOT NULL DEFAULT 'registration'");
    } catch (Throwable $ignored) {
        /* already present */
    }
    try {
        $pdo->exec("ALTER TABLE admission_application ADD COLUMN docs_grace_until DATE DEFAULT NULL");
    } catch (Throwable $ignored) {
        /* already present */
    }
    try {
        $pdo->exec("ALTER TABLE admission_application ADD COLUMN submission_channel VARCHAR(40) NOT NULL DEFAULT 'online'");
    } catch (Throwable $ignored) {
        /* already present */
    }
}

function admissions_seed(PDO $pdo): void
{
    // Official file list from بلاغ تسجيل + النظام الداخلي ch.2
    // application_types: all | initial,transfer,international | returning | transfer | optional codes
    $docs = [
        // code, label, required, types, sort, legalization, channel, printable, hint
        ['cin', 'Copie de la carte d’identité nationale', 1, 'all', 1, 0, 'app', '', 'Sur l’application (scan).'],
        ['bac', 'Copie certifiée du diplôme du baccalauréat', 1, 'initial,transfer,international', 2, 0, 'app', '', 'Scan de la copie conforme ; original à présenter à l’administration.'],
        ['releve_bac', 'Copie certifiée de la carte de notes du bac', 1, 'initial,transfer,international', 3, 0, 'app', '', 'Scan de la copie conforme.'],
        ['dossier_sante', 'Dossier de santé', 1, 'initial,transfer,international', 4, 0, 'app', '', 'À téléverser ; contrôle médical final à l’administration.'],
        ['certificat_medical', 'Certificat médical (études paramédicales)', 1, 'initial,transfer,international', 5, 0, 'app', '', 'Atteste l’aptitude aux études paramédicales.'],
        ['photo', 'Trois photos d’identité récentes', 1, 'all', 6, 0, 'app', '', 'Une image ou un PDF regroupant les 3 photos.'],
        ['formulaire_inscription', 'Formulaire d’inscription rempli et signé', 1, 'all', 7, 0, 'app', 'formulaire', 'Télécharger, remplir, signer, puis téléverser le scan.'],
        ['declaration_honneur', 'Déclaration sur l’honneur (non double inscription)', 1, 'initial,transfer,international', 8, 0, 'app', 'declaration', 'Télécharger, signer, téléverser.'],
        ['engagement_reglement', 'Engagement à respecter le règlement intérieur', 1, 'initial,transfer,international', 9, 1, 'both', 'reglement', 'Imprimer → signature légalisée → téléverser le scan ou déposer à l’administration.'],
        ['quittance_inscription', 'Quittance — frais d’inscription', 1, 'initial,transfer,international', 10, 0, 'app', '', 'Paiement hors ligne puis upload du reçu (vérifié par la finance).'],
        ['quittance_formation', 'Quittance — frais de formation', 1, 'initial,transfer,international', 11, 0, 'app', '', 'Paiement hors ligne puis upload du reçu.'],
        ['reconnaissance_dette', 'Reconnaissance de dette (paiement en 3 tranches)', 0, 'initial,transfer,international', 12, 1, 'both', 'dette', 'Uniquement si paiement échelonné : signature légalisée requise.'],
        ['demande_transfert', 'Demande de transfert / équivalence', 1, 'transfer', 13, 0, 'app', '', 'Obligatoire pour les transferts.'],
        ['lettre_motivation', 'Lettre de motivation', 1, 'transfer', 14, 0, 'app', '', 'Obligatoire pour les transferts.'],
        ['preuve_test', 'Preuve de réussite au test d’admission', 1, 'transfer', 15, 0, 'app', '', 'Attestation / résultat du test.'],
        ['quittance_test', 'Quittance — frais de test d’admission (150 DT)', 1, 'initial,transfer,international', 16, 0, 'app', '', 'Avant le passage devant la commission (150 DT).'],
        ['autre', 'Autre pièce', 0, 'all', 99, 0, 'app', '', ''],
    ];
    $exists = $pdo->prepare('SELECT id_doc_type FROM admission_doc_type WHERE code = ? LIMIT 1');
    $insert = $pdo->prepare(
        'INSERT INTO admission_doc_type
         (code, label, required, application_types, sort_order, requires_legalization, channel, printable_code, hint_fr, active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $update = $pdo->prepare(
        'UPDATE admission_doc_type
         SET label=?, required=?, application_types=?, sort_order=?, requires_legalization=?, channel=?, printable_code=?, hint_fr=?, active=1
         WHERE code=?'
    );
    foreach ($docs as $doc) {
        [$code, $label, $required, $types, $sort, $legal, $channel, $printable, $hint] = $doc;
        $exists->execute([$code]);
        if ($exists->fetchColumn()) {
            $update->execute([$label, $required, $types, $sort, $legal, $channel, $printable, $hint, $code]);
        } else {
            $insert->execute([$code, $label, $required, $types, $sort, $legal, $channel, $printable, $hint]);
        }
    }
    // Soft-disable legacy unused generic when replaced by official list (keep row for history)
    try {
        $pdo->exec("UPDATE admission_doc_type SET active = 0 WHERE code = 'autre'");
    } catch (Throwable $ignored) {
    }

    $enrollmentSteps = [
        ['code' => 'decision_confirmed', 'label' => 'Décision d’admission confirmée (application)', 'required' => 1, 'channel' => 'app'],
        ['code' => 'offer_accepted', 'label' => 'Offre acceptée par le candidat (application)', 'required' => 1, 'channel' => 'app'],
        ['code' => 'test_fee_paid', 'label' => 'Frais de test 150 DT vérifiés (finance)', 'required' => 1, 'channel' => 'admin'],
        ['code' => 'dossier_online_complete', 'label' => 'Dossier électronique complet (application)', 'required' => 1, 'channel' => 'app'],
        ['code' => 'legalized_received', 'label' => 'Pièces à signature légalisée reçues (administration)', 'required' => 1, 'channel' => 'admin'],
        ['code' => 'originals_checked', 'label' => 'Originaux / copies conformes contrôlés (administration)', 'required' => 1, 'channel' => 'admin'],
        ['code' => 'payment_ok', 'label' => 'Frais d’inscription et de formation réglés (finance)', 'required' => 1, 'channel' => 'admin'],
        ['code' => 'profile_confirmed', 'label' => 'Profil étudiant confirmé (scolarité)', 'required' => 1, 'channel' => 'admin'],
        ['code' => 'card_delivered', 'label' => 'Carte étudiant / attestation / badge remis (administration)', 'required' => 1, 'channel' => 'admin'],
    ];
    $defaults = [
        'offer_accept_days' => '14',
        'docs_grace_days' => '15',
        'test_fee_amount' => '150',
        'matricule_note' => 'Uses existing etudiant.num_inscription format INS###/YYYY — do not change.',
        'enrollment_steps' => json_encode($enrollmentSteps, JSON_UNESCAPED_UNICODE),
        'official_flow_note' => 'New students: بلاغ + نظام داخلي. Continuing: PA-SAE-01 (separate module).',
    ];
    $set = $pdo->prepare('INSERT IGNORE INTO admission_setting (setting_key, setting_value) VALUES (?, ?)');
    foreach ($defaults as $key => $value) {
        $set->execute([$key, $value]);
    }
    // Refresh enrollment steps to official checklist (idempotent overwrite for this key)
    admissions_save_setting_raw($pdo, 'enrollment_steps', $defaults['enrollment_steps']);
    admissions_save_setting_raw($pdo, 'docs_grace_days', '15');
    admissions_save_setting_raw($pdo, 'test_fee_amount', '150');
}

function admissions_save_setting_raw(PDO $pdo, string $key, string $value): void
{
    $exists = $pdo->prepare('SELECT 1 FROM admission_setting WHERE setting_key = ?');
    $exists->execute([$key]);
    if ($exists->fetchColumn()) {
        $pdo->prepare('UPDATE admission_setting SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
    } else {
        $pdo->prepare('INSERT INTO admission_setting (setting_key, setting_value) VALUES (?, ?)')->execute([$key, $value]);
    }
}

function admissions_setting(string $key, string $default = ''): string
{
    admissions_ensure();
    $stmt = db()->prepare('SELECT setting_value FROM admission_setting WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function admissions_save_setting(string $key, string $value): void
{
    admissions_ensure();
    $pdo = db();
    $exists = $pdo->prepare('SELECT 1 FROM admission_setting WHERE setting_key = ?');
    $exists->execute([$key]);
    if ($exists->fetchColumn()) {
        $pdo->prepare('UPDATE admission_setting SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
    } else {
        $pdo->prepare('INSERT INTO admission_setting (setting_key, setting_value) VALUES (?, ?)')->execute([$key, $value]);
    }
}

/** @return array<string, string> */
function admissions_statuses(): array
{
    return [
        'draft' => 'Brouillon',
        'submitted' => 'Soumise',
        'under_review' => 'En examen',
        'documents_incomplete' => 'Dossier incomplet',
        'eligible' => 'Éligible',
        'test_scheduled' => 'Test planifié',
        'test_completed' => 'Test terminé',
        'interview_scheduled' => 'Entretien planifié',
        'admission_pending' => 'Décision en attente',
        'admitted' => 'Admis',
        'waitlisted' => 'Liste d’attente',
        'rejected' => 'Refusé',
        'offer_accepted' => 'Offre acceptée',
        'registration_pending' => 'Inscription en cours',
        'enrolled' => 'Inscrit',
        'withdrawn' => 'Retiré',
        'expired' => 'Expiré',
    ];
}

/** @return array<string, list<string>> */
function admissions_transitions(): array
{
    return [
        'draft' => ['submitted', 'withdrawn'],
        'submitted' => ['under_review', 'documents_incomplete', 'withdrawn'],
        'under_review' => ['documents_incomplete', 'eligible', 'rejected', 'withdrawn'],
        'documents_incomplete' => ['under_review', 'eligible', 'withdrawn'],
        'eligible' => ['test_scheduled', 'interview_scheduled', 'admission_pending', 'withdrawn'],
        'test_scheduled' => ['test_completed', 'eligible', 'withdrawn'],
        'test_completed' => ['interview_scheduled', 'admission_pending', 'withdrawn'],
        'interview_scheduled' => ['admission_pending', 'withdrawn'],
        'admission_pending' => ['admitted', 'waitlisted', 'rejected', 'withdrawn'],
        'admitted' => ['offer_accepted', 'expired', 'withdrawn', 'waitlisted'],
        'waitlisted' => ['admitted', 'rejected', 'expired', 'withdrawn'],
        'rejected' => [],
        'offer_accepted' => ['registration_pending', 'withdrawn'],
        'registration_pending' => ['enrolled', 'withdrawn'],
        'enrolled' => [],
        'withdrawn' => [],
        'expired' => ['admitted', 'withdrawn'],
    ];
}

function admissions_can_transition(string $from, string $to): bool
{
    if ($from === $to) {
        return true;
    }
    return in_array($to, admissions_transitions()[$from] ?? [], true);
}

function admissions_actor_label(): string
{
    $role = (string) ($_SESSION['user_role'] ?? 'systeme');
    if ($role === 'admin') {
        return 'Administrateur';
    }
    if ($role === 'scolarite') {
        return 'Scolarité';
    }
    if ($role === 'candidat') {
        $id = (int) ($_SESSION['candidate_id'] ?? 0);
        $stmt = db()->prepare('SELECT prenom, nom FROM admission_candidate WHERE id_candidate = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: [];
        return trim((string) (($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? ''))) ?: 'Candidat';
    }
    return $role;
}

function admissions_transition(int $applicationId, string $newStatus, string $reason = '', string $field = 'status'): void
{
    admissions_ensure();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM admission_application WHERE id_application = ? FOR UPDATE');
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $owned = true;
    } else {
        $owned = false;
    }
    try {
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch();
        if (!$app) {
            throw new InvalidArgumentException('Candidature introuvable.');
        }
        $previous = (string) ($app[$field] ?? $app['status']);
        if ($field === 'status' && !admissions_can_transition($previous, $newStatus)) {
            throw new InvalidArgumentException('Transition non autorisée : ' . $previous . ' → ' . $newStatus . '.');
        }
        if ($field === 'status') {
            $pdo->prepare('UPDATE admission_application SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id_application = ?')
                ->execute([$newStatus, $applicationId]);
        } else {
            $allowed = ['document_status', 'test_status', 'interview_status', 'decision_status', 'payment_status', 'enrollment_status', 'account_status'];
            if (!in_array($field, $allowed, true)) {
                throw new InvalidArgumentException('Champ de statut invalide.');
            }
            $pdo->prepare("UPDATE admission_application SET {$field} = ?, updated_at = CURRENT_TIMESTAMP WHERE id_application = ?")
                ->execute([$newStatus, $applicationId]);
        }
        $pdo->prepare(
            'INSERT INTO admission_status_history
             (id_application, field_name, previous_status, new_status, changed_by_role, changed_by_id, changed_by_label, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $applicationId,
            $field,
            $previous,
            $newStatus,
            (string) ($_SESSION['user_role'] ?? 'systeme'),
            (int) ($_SESSION['candidate_id'] ?? $_SESSION['scolarite_id'] ?? 0) ?: null,
            mb_substr(admissions_actor_label(), 0, 160),
            mb_substr($reason, 0, 500),
        ]);
        if ($owned) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($owned && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function admissions_next_application_number(PDO $pdo): string
{
    $year = date('Y');
    $pdo->prepare('INSERT IGNORE INTO admission_application_seq (year_label, last_number) VALUES (?, 0)')->execute([$year]);
    $pdo->prepare('UPDATE admission_application_seq SET last_number = last_number + 1 WHERE year_label = ?')->execute([$year]);
    $stmt = $pdo->prepare('SELECT last_number FROM admission_application_seq WHERE year_label = ?');
    $stmt->execute([$year]);
    $n = (int) $stmt->fetchColumn();
    return 'CAND-' . $year . '-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

/**
 * Official student registration number — same format as Scolarite/inscription.php.
 * Do not invent a parallel matricule format.
 */
function admissions_next_num_inscription(PDO $pdo): string
{
    $currentYear = date('Y');
    $query = "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(SUBSTRING(num_inscription, 4), '/', 1) AS UNSIGNED)), 0) FROM etudiant WHERE num_inscription LIKE 'INS%/$currentYear'";
    $lastNumber = (int) $pdo->query($query)->fetchColumn();
    return 'INS' . str_pad((string) ($lastNumber + 1), 3, '0', STR_PAD_LEFT) . '/' . $currentYear;
}

function admissions_notify(int $candidateId, string $event, string $title, string $body = '', ?int $applicationId = null, string $link = 'espace_candidat.php'): void
{
    admissions_ensure();
    $since = date('Y-m-d H:i:s', time() - 120);
    $dup = db()->prepare(
        'SELECT 1 FROM admission_notification
         WHERE id_candidate = ? AND event_code = ? AND id_application IS NOT DISTINCT FROM ?
           AND created_at > ?
         LIMIT 1'
    );
    $dup->execute([$candidateId, $event, $applicationId, $since]);
    if ($dup->fetchColumn()) {
        return;
    }
    db()->prepare(
        'INSERT INTO admission_notification (id_candidate, id_application, event_code, title, body, link, email_status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $candidateId,
        $applicationId,
        $event,
        mb_substr($title, 0, 180),
        mb_substr($body, 0, 500),
        mb_substr($link, 0, 180),
        'skipped',
    ]);
}

/** @return list<array<string, mixed>> */
function admissions_doc_types(bool $activeOnly = true): array
{
    admissions_ensure();
    $sql = 'SELECT * FROM admission_doc_type';
    if ($activeOnly) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY sort_order, id_doc_type';
    return db()->query($sql)->fetchAll();
}

/** @return list<array<string, mixed>> */
function admissions_open_campaigns(): array
{
    admissions_ensure();
    return db()->query(
        "SELECT c.*, s.nom_spec
         FROM admission_campaign c
         LEFT JOIN specialite s ON s.id_spec = c.id_spec
         WHERE c.status = 'open'
           AND (c.opens_at IS NULL OR c.opens_at <= CURRENT_DATE)
           AND (c.closes_at IS NULL OR c.closes_at >= CURRENT_DATE)
         ORDER BY c.academic_year_label DESC, c.label"
    )->fetchAll();
}

function admissions_find_candidate_by_email(string $email): ?array
{
    admissions_ensure();
    $stmt = db()->prepare('SELECT * FROM admission_candidate WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $stmt->execute([trim($email)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function admissions_find_application(int $id): ?array
{
    admissions_ensure();
    $stmt = db()->prepare(
        'SELECT a.*, c.email, c.nom, c.prenom, c.nom_ar, c.prenom_ar, c.cin_passeport, c.num_tel,
                c.date_naissance, c.lieu_naissance, c.sexe, c.nationalite, c.adresse, c.ville, c.pays,
                c.id_etudiant AS candidate_etudiant_id, camp.label AS campaign_label, s.nom_spec
         FROM admission_application a
         JOIN admission_candidate c ON c.id_candidate = a.id_candidate
         JOIN admission_campaign camp ON camp.id_campaign = a.id_campaign
         LEFT JOIN specialite s ON s.id_spec = a.id_spec
         WHERE a.id_application = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function admissions_refresh_document_status(int $applicationId): void
{
    admissions_ensure();
    $app = admissions_find_application($applicationId);
    $appType = (string) ($app['application_type'] ?? 'initial');
    $required = admissions_doc_types_for_application($appType, true);
    $required = array_values(array_filter($required, static fn(array $d): bool => (int) $d['required'] === 1));
    $docs = db()->prepare('SELECT id_doc_type, status FROM admission_document WHERE id_application = ?');
    $docs->execute([$applicationId]);
    $byType = [];
    foreach ($docs->fetchAll() as $row) {
        $byType[(int) $row['id_doc_type']] = (string) $row['status'];
    }
    $missing = 0;
    $rejected = 0;
    $pending = 0;
    $verified = 0;
    foreach ($required as $type) {
        $status = $byType[(int) $type['id_doc_type']] ?? 'missing';
        if ($status === 'missing' || $status === '') {
            $missing++;
        } elseif ($status === 'rejected' || $status === 'replacement_requested') {
            $rejected++;
        } elseif ($status === 'verified') {
            $verified++;
        } else {
            $pending++;
        }
    }
    if ($missing > 0) {
        $docStatus = 'incomplete';
    } elseif ($rejected > 0) {
        $docStatus = 'replacement_requested';
    } elseif ($pending > 0) {
        $docStatus = 'under_verification';
    } elseif ($verified === count($required) && count($required) > 0) {
        $docStatus = 'complete';
    } else {
        $docStatus = 'incomplete';
    }
    admissions_transition($applicationId, $docStatus, 'Recalcul dossier', 'document_status');
    if ($docStatus === 'complete') {
        try {
            db()->prepare(
                'UPDATE admission_enrollment_checklist SET completed = 1, completed_at = CURRENT_TIMESTAMP, completed_by = ?
                 WHERE id_application = ? AND step_code = ? AND completed = 0'
            )->execute(['Système', $applicationId, 'dossier_online_complete']);
        } catch (Throwable $ignored) {
        }
    }
}

/**
 * @param array<string, mixed> $data
 * @param array<string, array<string, mixed>> $files
 * @return array{application_number:string,id_application:int,id_candidate:int,temp_password:string}
 */
function admissions_submit_preregistration(array $data, array $files = []): array
{
    admissions_ensure();
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $cin = trim((string) ($data['cin_passeport'] ?? ''));
    $campaignId = (int) ($data['id_campaign'] ?? 0);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Adresse e-mail invalide.');
    }
    if ($cin === '') {
        throw new InvalidArgumentException('CIN / passeport obligatoire.');
    }
    if ($campaignId < 1) {
        throw new InvalidArgumentException('Choisissez une campagne d’admission.');
    }
    $campaign = db()->prepare("SELECT * FROM admission_campaign WHERE id_campaign = ? AND status = 'open'");
    $campaign->execute([$campaignId]);
    $camp = $campaign->fetch();
    if (!$camp) {
        throw new InvalidArgumentException('Campagne fermée ou introuvable.');
    }

    $dup = db()->prepare(
        'SELECT a.id_application FROM admission_application a
         JOIN admission_candidate c ON c.id_candidate = a.id_candidate
         WHERE a.id_campaign = ? AND (LOWER(c.email) = ? OR c.cin_passeport = ?)
           AND a.status NOT IN ("withdrawn","rejected","expired")
         LIMIT 1'
    );
    $dup->execute([$campaignId, $email, $cin]);
    if ($dup->fetchColumn()) {
        throw new InvalidArgumentException('Une candidature active existe déjà pour cette campagne (e-mail ou CIN).');
    }

    $existingStudent = db()->prepare('SELECT id_etudiant FROM etudiant WHERE cin_passeport = ? OR LOWER(email) = ? LIMIT 1');
    $existingStudent->execute([$cin, $email]);
    if ($existingStudent->fetchColumn()) {
        throw new InvalidArgumentException('Ce CIN / e-mail correspond déjà à un étudiant inscrit. Contactez la scolarité.');
    }

    $required = ['nom', 'prenom', 'date_naissance', 'num_tel'];
    foreach ($required as $field) {
        if (trim((string) ($data[$field] ?? '')) === '') {
            throw new InvalidArgumentException('Champ obligatoire manquant : ' . $field . '.');
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $candidate = admissions_find_candidate_by_email($email);
        $tempPassword = '';
        if ($candidate) {
            $candidateId = (int) $candidate['id_candidate'];
            $pdo->prepare(
                'UPDATE admission_candidate SET nom=?, prenom=?, nom_ar=?, prenom_ar=?, date_naissance=?, lieu_naissance=?,
                 sexe=?, nationalite=?, num_tel=?, adresse=?, ville=?, pays=?, cin_passeport=?, updated_at=CURRENT_TIMESTAMP
                 WHERE id_candidate=?'
            )->execute([
                trim((string) $data['nom']),
                trim((string) $data['prenom']),
                trim((string) ($data['nom_ar'] ?? '')),
                trim((string) ($data['prenom_ar'] ?? '')),
                (string) $data['date_naissance'],
                trim((string) ($data['lieu_naissance'] ?? '')),
                trim((string) ($data['sexe'] ?? '')),
                trim((string) ($data['nationalite'] ?? 'Tunisienne')),
                trim((string) $data['num_tel']),
                trim((string) ($data['adresse'] ?? '')),
                trim((string) ($data['ville'] ?? '')),
                trim((string) ($data['pays'] ?? 'Tunisie')),
                $cin,
                $candidateId,
            ]);
        } else {
            $tempPassword = bin2hex(random_bytes(4));
            $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
            $token = bin2hex(random_bytes(16));
            $expires = date('Y-m-d H:i:s', time() + 7 * 86400);
            $pdo->prepare(
                'INSERT INTO admission_candidate
                 (email, password, cin_passeport, nom, prenom, nom_ar, prenom_ar, date_naissance, lieu_naissance,
                  sexe, nationalite, num_tel, adresse, ville, pays, activation_token, activation_expires, email_verified)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)'
            )->execute([
                $email,
                $hash,
                $cin,
                trim((string) $data['nom']),
                trim((string) $data['prenom']),
                trim((string) ($data['nom_ar'] ?? '')),
                trim((string) ($data['prenom_ar'] ?? '')),
                (string) $data['date_naissance'],
                trim((string) ($data['lieu_naissance'] ?? '')),
                trim((string) ($data['sexe'] ?? '')),
                trim((string) ($data['nationalite'] ?? 'Tunisienne')),
                trim((string) $data['num_tel']),
                trim((string) ($data['adresse'] ?? '')),
                trim((string) ($data['ville'] ?? '')),
                trim((string) ($data['pays'] ?? 'Tunisie')),
                $token,
                $expires,
            ]);
            $candidateId = (int) $pdo->lastInsertId();
        }

        $number = admissions_next_application_number($pdo);
        $specId = (int) ($data['id_spec'] ?? $camp['id_spec'] ?? 0) ?: null;
        $moyenne = $data['moyenne_bac'] !== '' && $data['moyenne_bac'] !== null
            ? (float) $data['moyenne_bac']
            : null;
        $pdo->prepare(
            'INSERT INTO admission_application
             (application_number, id_candidate, id_campaign, id_spec, application_type, academic_year_label,
              status, document_status, annee_bac, specialite_bac, moyenne_bac, etablissement_origine,
              diplome_info, etudes_anterieures, notes_candidat, submitted_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, CURRENT_TIMESTAMP)'
        )->execute([
            $number,
            $candidateId,
            $campaignId,
            $specId,
            (string) ($data['application_type'] ?? 'initial'),
            (string) $camp['academic_year_label'],
            'submitted',
            'incomplete',
            trim((string) ($data['annee_bac'] ?? '')),
            trim((string) ($data['specialite_bac'] ?? '')),
            $moyenne,
            trim((string) ($data['etablissement_origine'] ?? '')),
            trim((string) ($data['diplome_info'] ?? '')),
            trim((string) ($data['etudes_anterieures'] ?? '')),
            trim((string) ($data['notes_candidat'] ?? '')),
        ]);
        $appId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO admission_status_history
             (id_application, field_name, previous_status, new_status, changed_by_role, changed_by_label, reason)
             VALUES (?, "status", "", "submitted", "candidat", ?, ?)'
        )->execute([$appId, trim((string) $data['prenom'] . ' ' . (string) $data['nom']), 'Préinscription en ligne']);

        foreach ($files as $code => $file) {
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $typeStmt = $pdo->prepare('SELECT id_doc_type FROM admission_doc_type WHERE code = ? AND active = 1');
            $typeStmt->execute([(string) $code]);
            $typeId = (int) $typeStmt->fetchColumn();
            if ($typeId < 1) {
                continue;
            }
            admissions_store_document($pdo, $appId, $typeId, $file);
        }

        admissions_seed_checklist($pdo, $appId);
        $pdo->commit();

        admissions_refresh_document_status($appId);
        admissions_notify(
            $candidateId,
            'prereg_submitted',
            'Préinscription enregistrée',
            'Référence ' . $number . '. Connectez-vous à l’espace candidat.',
            $appId
        );
        if ($tempPassword !== '') {
            admissions_notify(
                $candidateId,
                'account_created',
                'Compte candidat créé',
                'Utilisez votre e-mail et le mot de passe temporaire fourni à la confirmation.',
                $appId
            );
        }

        return [
            'application_number' => $number,
            'id_application' => $appId,
            'id_candidate' => $candidateId,
            'temp_password' => $tempPassword,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function admissions_seed_checklist(PDO $pdo, int $applicationId): void
{
    $raw = admissions_setting('enrollment_steps', '[]');
    $steps = json_decode($raw, true);
    if (!is_array($steps)) {
        return;
    }
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO admission_enrollment_checklist (id_application, step_code, step_label, required)
         VALUES (?, ?, ?, ?)'
    );
    $updateLabel = $pdo->prepare(
        'UPDATE admission_enrollment_checklist SET step_label = ?, required = ? WHERE id_application = ? AND step_code = ?'
    );
    foreach ($steps as $step) {
        if (!is_array($step) || empty($step['code'])) {
            continue;
        }
        $code = (string) $step['code'];
        $label = (string) ($step['label'] ?? $code);
        $required = (int) ($step['required'] ?? 1);
        $insert->execute([$applicationId, $code, $label, $required]);
        $updateLabel->execute([$label, $required, $applicationId, $code]);
    }
}

function admissions_docs_dir(): string
{
    $dir = storage_path('admissions-docs');
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Stockage des documents d’admission inaccessible.');
    }
    return $dir;
}

function admissions_store_document(PDO $pdo, int $applicationId, int $docTypeId, array $file): void
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Échec d’envoi du fichier.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    $typeRow = $pdo->prepare('SELECT * FROM admission_doc_type WHERE id_doc_type = ?');
    $typeRow->execute([$docTypeId]);
    $type = $typeRow->fetch();
    if (!$type) {
        throw new InvalidArgumentException('Type de document inconnu.');
    }
    $max = ((int) $type['max_size_kb']) * 1024;
    if ($tmp === '' || !is_uploaded_file($tmp) || $size < 1 || $size > $max) {
        throw new InvalidArgumentException('Fichier trop volumineux ou invalide (max ' . (int) $type['max_size_kb'] . ' Ko).');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $allowedMimes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $ext = $allowedMimes[$mime] ?? '';
    $allow = array_map('trim', explode(',', strtolower((string) $type['mime_allow'])));
    if ($ext === '' || !in_array($ext, $allow, true)) {
        throw new InvalidArgumentException('Format non autorisé pour « ' . $type['label'] . ' ».');
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $target = admissions_docs_dir() . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Enregistrement du fichier impossible.');
    }
    @chmod($target, 0640);
    $pdo->prepare(
        'UPDATE admission_document SET status = "replaced" WHERE id_application = ? AND id_doc_type = ? AND status NOT IN ("replaced")'
    )->execute([$applicationId, $docTypeId]);
    $pdo->prepare(
        'INSERT INTO admission_document (id_application, id_doc_type, stored_name, original_name, mime, size_bytes, status)
         VALUES (?, ?, ?, ?, ?, ?, "uploaded")'
    )->execute([
        $applicationId,
        $docTypeId,
        $stored,
        mb_substr((string) ($file['name'] ?? $stored), 0, 180),
        $mime,
        $size,
    ]);
}

function admissions_document_path(string $stored): ?string
{
    if (!preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/', $stored)) {
        return null;
    }
    $root = realpath(admissions_docs_dir());
    $path = realpath(admissions_docs_dir() . DIRECTORY_SEPARATOR . $stored);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        return null;
    }
    return $path;
}

/** @return array<string, array{label:string,state:string}> */
function admissions_progress_steps(array $app): array
{
    $status = (string) ($app['status'] ?? '');
    $order = [
        'submitted' => 'Demande en ligne',
        'documents' => 'Dossier & quittances',
        'test' => 'Test d’admission (150 DT)',
        'interview' => 'Commission',
        'decision' => 'Décision',
        'offer' => 'Acceptation',
        'registration' => 'Inscription finale',
        'enrolled' => 'Carte / compte étudiant',
    ];
    $rank = match (true) {
        in_array($status, ['enrolled'], true) => 8,
        in_array($status, ['registration_pending', 'offer_accepted'], true) => 7,
        in_array($status, ['admitted'], true) => 6,
        in_array($status, ['admission_pending', 'waitlisted'], true) => 5,
        in_array($status, ['interview_scheduled'], true) => 4,
        in_array($status, ['test_scheduled', 'test_completed'], true) => 3,
        in_array($status, ['eligible', 'under_review', 'documents_incomplete'], true) => 2,
        in_array($status, ['submitted'], true) => 1,
        in_array($status, ['rejected', 'withdrawn', 'expired'], true) => 0,
        default => 1,
    };
    $i = 0;
    $out = [];
    foreach ($order as $key => $label) {
        $i++;
        if ($status === 'rejected' && $key === 'decision') {
            $state = 'rejected';
        } elseif ($rank === 0) {
            $state = $i === 1 ? 'completed' : 'not_started';
        } elseif ($i < $rank) {
            $state = 'completed';
        } elseif ($i === $rank) {
            $state = in_array($status, ['documents_incomplete'], true) && $key === 'documents'
                ? 'action_required'
                : 'in_progress';
        } else {
            $state = 'not_started';
        }
        if ($key === 'documents' && ($app['document_status'] ?? '') === 'replacement_requested') {
            $state = 'action_required';
        }
        $out[$key] = ['label' => $label, 'state' => $state];
    }
    return $out;
}

/**
 * Final enrollment: create etudiant + etudiant_compte, keep INS###/YYYY, upgrade candidate.
 * Idempotent: second call returns existing link.
 *
 * @return array{id_etudiant:int,num_inscription:string}
 */
function admissions_finalize_enrollment(int $applicationId): array
{
    admissions_ensure();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM admission_application WHERE id_application = ? FOR UPDATE');
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch();
        if (!$app) {
            throw new InvalidArgumentException('Candidature introuvable.');
        }
        if ((string) $app['status'] === 'enrolled' && (int) ($app['id_etudiant'] ?? 0) > 0) {
            $num = $pdo->prepare('SELECT num_inscription FROM etudiant WHERE id_etudiant = ?');
            $num->execute([(int) $app['id_etudiant']]);
            $pdo->commit();
            return ['id_etudiant' => (int) $app['id_etudiant'], 'num_inscription' => (string) $num->fetchColumn()];
        }
        if (!in_array((string) $app['status'], ['offer_accepted', 'registration_pending'], true)) {
            throw new InvalidArgumentException('La candidature doit être en offre acceptée / inscription en cours.');
        }
        if ((string) $app['decision_status'] !== 'admitted' && (string) $app['status'] !== 'offer_accepted' && (string) $app['status'] !== 'registration_pending') {
            /* decision_status should be admitted */
        }
        $dec = $pdo->prepare(
            'SELECT decision FROM admission_decision WHERE id_application = ? AND published = 1 ORDER BY id_decision DESC LIMIT 1'
        );
        $dec->execute([$applicationId]);
        if ((string) $dec->fetchColumn() !== 'admitted') {
            throw new InvalidArgumentException('Aucune décision « admis » publiée.');
        }

        $checks = $pdo->prepare(
            'SELECT step_label FROM admission_enrollment_checklist WHERE id_application = ? AND required = 1 AND completed = 0'
        );
        $checks->execute([$applicationId]);
        $missing = $checks->fetchAll(PDO::FETCH_COLUMN);
        if ($missing) {
            throw new InvalidArgumentException('Étapes manquantes : ' . implode(', ', $missing) . '.');
        }

        $candStmt = $pdo->prepare('SELECT * FROM admission_candidate WHERE id_candidate = ? FOR UPDATE');
        $candStmt->execute([(int) $app['id_candidate']]);
        $cand = $candStmt->fetch();
        if (!$cand) {
            throw new InvalidArgumentException('Candidat introuvable.');
        }

        if ((int) ($cand['id_etudiant'] ?? 0) > 0) {
            $studentId = (int) $cand['id_etudiant'];
            $num = $pdo->prepare('SELECT num_inscription FROM etudiant WHERE id_etudiant = ?');
            $num->execute([$studentId]);
            $numInscription = (string) $num->fetchColumn();
        } else {
            $exists = $pdo->prepare('SELECT id_etudiant, num_inscription FROM etudiant WHERE cin_passeport = ? OR LOWER(email) = LOWER(?) LIMIT 1');
            $exists->execute([(string) $cand['cin_passeport'], (string) $cand['email']]);
            $existing = $exists->fetch();
            if ($existing) {
                $studentId = (int) $existing['id_etudiant'];
                $numInscription = (string) $existing['num_inscription'];
            } else {
                $specId = (int) ($app['id_spec'] ?? 0);
                if ($specId < 1) {
                    throw new InvalidArgumentException('Spécialité manquante pour l’inscription.');
                }
                $numInscription = admissions_next_num_inscription($pdo);
                $pdo->prepare(
                    'INSERT INTO etudiant
                     (nom, prenom, nom_ar, prenom_ar, date_naissance, lieu_naissance, lieu_naissance_ar, sexe, nationalite,
                      etat_civil, cin_passeport, num_tel, adresse, email, num_inscription, date_inscrit, id_spec, niveau,
                      annee_bac, specialite_bac)
                     VALUES (?,?,?,?,?,?,"",?,?,"",?,?,?,?,?,CURRENT_DATE,?,?,?,?)'
                )->execute([
                    (string) $cand['nom'],
                    (string) $cand['prenom'],
                    (string) $cand['nom_ar'],
                    (string) $cand['prenom_ar'],
                    $cand['date_naissance'],
                    (string) $cand['lieu_naissance'],
                    (string) $cand['sexe'],
                    (string) $cand['nationalite'],
                    (string) $cand['cin_passeport'],
                    (string) $cand['num_tel'],
                    trim((string) $cand['adresse'] . ' ' . (string) $cand['ville']),
                    (string) $cand['email'],
                    $numInscription,
                    $specId,
                    '1',
                    (string) $app['annee_bac'],
                    (string) $app['specialite_bac'],
                ]);
                $studentId = (int) $pdo->lastInsertId();
                if (function_exists('assign_student_to_academic_groups')) {
                    $specName = $pdo->prepare('SELECT nom_spec FROM specialite WHERE id_spec = ?');
                    $specName->execute([$specId]);
                    $name = (string) $specName->fetchColumn();
                    if (stripos($name, 'commun') !== false || stripos($name, 'tronc') !== false) {
                        if (function_exists('assign_tronc_commun_groups')) {
                            assign_tronc_commun_groups($pdo, $studentId);
                        }
                    } else {
                        assign_student_to_academic_groups($pdo, $studentId, '1');
                    }
                }
            }
        }

        ensure_etudiant_compte_table($pdo);
        $acct = $pdo->prepare('SELECT id_etudiant FROM etudiant_compte WHERE id_etudiant = ?');
        $acct->execute([$studentId]);
        if (!$acct->fetchColumn()) {
            $password = (string) $cand['password'];
            if ($password === '') {
                $password = password_hash(bin2hex(random_bytes(4)), PASSWORD_DEFAULT);
            }
            $pdo->prepare(
                'INSERT INTO etudiant_compte (id_etudiant, password, active, auth_generation) VALUES (?, ?, 1, 1)'
            )->execute([$studentId, $password]);
        } else {
            $pdo->prepare('UPDATE etudiant_compte SET active = 1, password = ? WHERE id_etudiant = ?')
                ->execute([(string) $cand['password'] ?: password_hash(bin2hex(random_bytes(4)), PASSWORD_DEFAULT), $studentId]);
        }

        $pdo->prepare(
            'UPDATE admission_application
             SET status = "enrolled", enrollment_status = "completed", account_status = "student_active",
                 id_etudiant = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id_application = ?'
        )->execute([$studentId, $applicationId]);
        $pdo->prepare(
            'UPDATE admission_candidate SET id_etudiant = ?, updated_at = CURRENT_TIMESTAMP WHERE id_candidate = ?'
        )->execute([$studentId, (int) $app['id_candidate']]);
        $pdo->prepare(
            'INSERT INTO admission_status_history
             (id_application, field_name, previous_status, new_status, changed_by_role, changed_by_label, reason)
             VALUES (?, "status", ?, "enrolled", ?, ?, ?)'
        )->execute([
            $applicationId,
            (string) $app['status'],
            (string) ($_SESSION['user_role'] ?? 'admin'),
            admissions_actor_label(),
            'Inscription finale — ' . $numInscription,
        ]);

        $pdo->commit();

        admissions_notify(
            (int) $app['id_candidate'],
            'enrolled',
            'Inscription validée',
            'Vous êtes inscrit sous le numéro ' . $numInscription . '. Connectez-vous à l’espace étudiant (CIN).',
            $applicationId,
            'connexion_etudiant.php'
        );
        if (function_exists('audit_record')) {
            try {
                audit_record('admission', 'enroll', 'Inscription finale ' . $numInscription . ' (app ' . $applicationId . ')');
            } catch (Throwable $ignored) {
                audit_record('compte', 'enroll', 'Inscription finale admissions ' . $numInscription);
            }
        }

        return ['id_etudiant' => $studentId, 'num_inscription' => $numInscription];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function admissions_publish_decision(int $applicationId, string $decision, string $comments = '', string $reason = '', string $committee = ''): void
{
    admissions_ensure();
    if (!in_array($decision, ['admitted', 'waitlisted', 'rejected', 'additional_evaluation'], true)) {
        throw new InvalidArgumentException('Décision invalide.');
    }
    $app = admissions_find_application($applicationId);
    if (!$app) {
        throw new InvalidArgumentException('Candidature introuvable.');
    }
    $days = (int) admissions_setting('offer_accept_days', '14');
    $deadline = $decision === 'admitted' ? date('Y-m-d', strtotime('+' . max(1, $days) . ' days')) : null;
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO admission_decision
         (id_application, decision, decision_date, committee_label, comments, reason, published, published_at, offer_deadline, decided_by)
         VALUES (?, ?, CURRENT_DATE, ?, ?, ?, 1, CURRENT_TIMESTAMP, ?, ?)'
    )->execute([
        $applicationId,
        $decision,
        mb_substr($committee, 0, 255),
        $comments,
        mb_substr($reason, 0, 500),
        $deadline,
        admissions_actor_label(),
    ]);

    $map = [
        'admitted' => 'admitted',
        'waitlisted' => 'waitlisted',
        'rejected' => 'rejected',
        'additional_evaluation' => 'admission_pending',
    ];
    $target = $map[$decision];
    if (!admissions_can_transition((string) $app['status'], $target)) {
        if (admissions_can_transition((string) $app['status'], 'admission_pending')) {
            admissions_transition($applicationId, 'admission_pending', 'Préparation décision');
        }
    }
    $fresh = admissions_find_application($applicationId);
    if ($fresh && admissions_can_transition((string) $fresh['status'], $target)) {
        admissions_transition($applicationId, $target, $reason !== '' ? $reason : 'Décision publiée');
    } elseif ($fresh && (string) $fresh['status'] !== $target) {
        db()->prepare('UPDATE admission_application SET status = ?, decision_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id_application = ?')
            ->execute([$target, $decision === 'additional_evaluation' ? 'pending' : $decision, $applicationId]);
        db()->prepare(
            'INSERT INTO admission_status_history
             (id_application, field_name, previous_status, new_status, changed_by_role, changed_by_label, reason)
             VALUES (?, "status", ?, ?, ?, ?, ?)'
        )->execute([
            $applicationId,
            (string) $fresh['status'],
            $target,
            (string) ($_SESSION['user_role'] ?? 'admin'),
            admissions_actor_label(),
            'Publication décision (forcé)',
        ]);
    }
    admissions_transition($applicationId, $decision === 'additional_evaluation' ? 'pending' : $decision, 'Décision', 'decision_status');

    $titles = [
        'admitted' => 'Vous êtes admis(e)',
        'waitlisted' => 'Liste d’attente',
        'rejected' => 'Décision défavorable',
        'additional_evaluation' => 'Évaluation complémentaire',
    ];
    admissions_notify(
        (int) $app['id_candidate'],
        'decision_published',
        $titles[$decision],
        $decision === 'admitted' && $deadline
            ? 'Merci de confirmer avant le ' . $deadline . '.'
            : mb_substr($reason !== '' ? $reason : $comments, 0, 500),
        $applicationId
    );
}

function admissions_stats(): array
{
    admissions_ensure();
    $pdo = db();
    $counts = static function (string $sql) use ($pdo): int {
        return (int) $pdo->query($sql)->fetchColumn();
    };
    return [
        'total' => $counts('SELECT COUNT(*) FROM admission_application'),
        'submitted' => $counts("SELECT COUNT(*) FROM admission_application WHERE status = 'submitted'"),
        'under_review' => $counts("SELECT COUNT(*) FROM admission_application WHERE status = 'under_review'"),
        'documents_incomplete' => $counts("SELECT COUNT(*) FROM admission_application WHERE document_status IN ('incomplete','replacement_requested')"),
        'eligible' => $counts("SELECT COUNT(*) FROM admission_application WHERE status = 'eligible'"),
        'test_scheduled' => $counts("SELECT COUNT(*) FROM admission_application WHERE status = 'test_scheduled'"),
        'test_completed' => $counts("SELECT COUNT(*) FROM admission_application WHERE status = 'test_completed'"),
        'interview_scheduled' => $counts("SELECT COUNT(*) FROM admission_application WHERE status = 'interview_scheduled'"),
        'admission_pending' => $counts("SELECT COUNT(*) FROM admission_application WHERE status = 'admission_pending'"),
        'admitted' => $counts("SELECT COUNT(*) FROM admission_application WHERE status = 'admitted'"),
        'waitlisted' => $counts("SELECT COUNT(*) FROM admission_application WHERE status = 'waitlisted'"),
        'rejected' => $counts("SELECT COUNT(*) FROM admission_application WHERE status = 'rejected'"),
        'registration_pending' => $counts("SELECT COUNT(*) FROM admission_application WHERE status IN ('offer_accepted','registration_pending')"),
        'enrolled' => $counts("SELECT COUNT(*) FROM admission_application WHERE status = 'enrolled'"),
    ];
}

function is_candidate(): bool
{
    return ($_SESSION['user_role'] ?? '') === 'candidat';
}

function current_candidate_id(): int
{
    return (int) ($_SESSION['candidate_id'] ?? 0);
}

function require_candidate(): void
{
    admissions_ensure();
    if (!logged_in() || !is_candidate() || current_candidate_id() < 1) {
        header('Location: connexion_candidat.php');
        exit;
    }
    $stmt = db()->prepare('SELECT active, auth_generation FROM admission_candidate WHERE id_candidate = ? LIMIT 1');
    $stmt->execute([current_candidate_id()]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['active'] !== 1 || (int) $row['auth_generation'] !== (int) ($_SESSION['candidate_auth_generation'] ?? -1)) {
        $_SESSION = [];
        header('Location: connexion_candidat.php');
        exit;
    }
}

function require_admissions_permission(string $permission = 'admissions.view'): void
{
    admissions_ensure();
    require_permission($permission);
}

function admissions_application_types(): array
{
    return [
        'initial' => 'Étudiant nouveau',
        'transfer' => 'Transfert / équivalence',
        'international' => 'Étudiant étranger',
        'returning' => 'Réinscription (ancien de l’établissement)',
        'parallel' => 'Admission parallèle',
    ];
}

/**
 * Doc types applicable to an application type (official UPSG lists).
 *
 * @return list<array<string, mixed>>
 */
function admissions_doc_types_for_application(string $applicationType, bool $activeOnly = true): array
{
    $all = admissions_doc_types($activeOnly);
    $type = $applicationType !== '' ? $applicationType : 'initial';
    $out = [];
    foreach ($all as $doc) {
        $types = strtolower(trim((string) ($doc['application_types'] ?? 'all')));
        if ($types === '' || $types === 'all') {
            $out[] = $doc;
            continue;
        }
        $parts = array_map('trim', explode(',', $types));
        if (in_array($type, $parts, true)) {
            $out[] = $doc;
        }
    }
    return $out;
}

/** @return array<string, array{title:string,channel:string,hint:string}> */
function admissions_printable_forms(): array
{
    return [
        'formulaire' => [
            'title' => 'Formulaire d’inscription',
            'channel' => 'app',
            'hint' => 'Remplir, signer, téléverser le scan.',
        ],
        'declaration' => [
            'title' => 'Déclaration sur l’honneur',
            'channel' => 'app',
            'hint' => 'Signer puis téléverser.',
        ],
        'reglement' => [
            'title' => 'Engagement — règlement intérieur',
            'channel' => 'both',
            'hint' => 'Signature légalisée obligatoire — dépôt admin ou scan.',
        ],
        'dette' => [
            'title' => 'Reconnaissance de dette (3 tranches)',
            'channel' => 'both',
            'hint' => 'Uniquement si paiement échelonné — signature légalisée.',
        ],
        'test_request' => [
            'title' => 'Mettaleb — passage du test d’admission',
            'channel' => 'admin',
            'hint' => 'Imprimer, payer 150 DT, se présenter à la commission.',
        ],
    ];
}

/** @return array{app:list<array<string,mixed>>,admin:list<array<string,mixed>>} */
function admissions_checklist_by_channel(int $applicationId): array
{
    admissions_ensure();
    $rows = db()->prepare('SELECT * FROM admission_enrollment_checklist WHERE id_application = ? ORDER BY id_check');
    $rows->execute([$applicationId]);
    $all = $rows->fetchAll();
    $raw = json_decode(admissions_setting('enrollment_steps', '[]'), true);
    $meta = [];
    if (is_array($raw)) {
        foreach ($raw as $step) {
            if (is_array($step) && !empty($step['code'])) {
                $meta[(string) $step['code']] = (string) ($step['channel'] ?? 'admin');
            }
        }
    }
    $app = [];
    $admin = [];
    foreach ($all as $row) {
        $channel = $meta[(string) $row['step_code']] ?? 'admin';
        if ($channel === 'app') {
            $app[] = $row;
        } else {
            $admin[] = $row;
        }
    }
    return ['app' => $app, 'admin' => $admin];
}

function admissions_payment_kinds(): array
{
    return [
        'test_fee' => 'Frais de test d’admission (150 DT)',
        'registration' => 'Frais d’inscription',
        'tuition' => 'Frais de formation',
        'installment' => 'Tranche / reconnaissance de dette',
        'other' => 'Autre',
    ];
}

function admissions_test_types(): array
{
    return [
        'psychometric' => 'Test psychométrique',
        'logical' => 'Raisonnement logique',
        'numerical' => 'Raisonnement numérique',
        'verbal' => 'Raisonnement verbal',
        'attention' => 'Attention et concentration',
        'interview' => 'Entretien individuel',
        'other' => 'Autre',
    ];
}
