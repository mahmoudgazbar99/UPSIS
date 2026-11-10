<?php
declare(strict_types=1);

/**
 * Result publication: seuil admis, rachat pending, send relevés to student profiles.
 */

function result_publication_ensure(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    $pdo = db();
    if (!db_table_exists($pdo, 'resultat_campagne')) {
        $pdo->exec(
            "CREATE TABLE resultat_campagne (
              id_campagne INT UNSIGNED NOT NULL AUTO_INCREMENT,
              annee_scolaire VARCHAR(32) NOT NULL,
              status VARCHAR(20) NOT NULL DEFAULT 'ouverte',
              published_at DATETIME NULL DEFAULT NULL,
              published_by INT NULL DEFAULT NULL,
              PRIMARY KEY (id_campagne),
              UNIQUE KEY uk_resultat_annee (annee_scolaire)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
    if (!db_table_exists($pdo, 'etudiant_resultat')) {
        $pdo->exec(
            "CREATE TABLE etudiant_resultat (
              id_resultat INT UNSIGNED NOT NULL AUTO_INCREMENT,
              id_etudiant INT UNSIGNED NOT NULL,
              annee_scolaire VARCHAR(32) NOT NULL,
              moyenne DECIMAL(6,2) NULL DEFAULT NULL,
              decision VARCHAR(32) NOT NULL DEFAULT 'en_attente',
              decided_at DATETIME NULL DEFAULT NULL,
              decided_by INT NULL DEFAULT NULL,
              published_at DATETIME NULL DEFAULT NULL,
              transcript_payload LONGTEXT NULL,
              PRIMARY KEY (id_resultat),
              UNIQUE KEY uk_etudiant_resultat_year (id_etudiant, annee_scolaire),
              KEY idx_resultat_decision (annee_scolaire, decision),
              CONSTRAINT fk_etudiant_resultat FOREIGN KEY (id_etudiant)
                REFERENCES etudiant (id_etudiant) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

function result_active_year(): string
{
    return academic_active_year_label();
}

function result_pass_threshold(?int $programId = null): float
{
    return (float) progression_policy($programId)['pass_threshold'];
}

function result_decision_label(string $decision): string
{
    return match ($decision) {
        'admis' => 'Admis',
        'admis_par_rachat' => 'Admis par rachat',
        'refusee' => 'Refusée',
        'en_attente' => 'En attente',
        default => $decision,
    };
}

function result_campagne(string $year = ''): array
{
    result_publication_ensure();
    $year = $year !== '' ? $year : result_active_year();
    $pdo = db();
    $query = $pdo->prepare('SELECT * FROM resultat_campagne WHERE annee_scolaire = ? LIMIT 1');
    $query->execute([$year]);
    $row = $query->fetch();
    if ($row) {
        return $row;
    }
    $pdo->prepare('INSERT INTO resultat_campagne (annee_scolaire, status) VALUES (?, "ouverte")')->execute([$year]);
    $query->execute([$year]);
    return $query->fetch() ?: [
        'id_campagne' => 0,
        'annee_scolaire' => $year,
        'status' => 'ouverte',
        'published_at' => null,
        'published_by' => null,
    ];
}

function result_is_published(string $year = ''): bool
{
    $campagne = result_campagne($year);
    return (string) ($campagne['status'] ?? '') === 'publiee';
}

/**
 * @return list<array<string, mixed>>
 */
function result_student_marks(int $studentId): array
{
    $pdo = db();
    $hasRetake = (bool) $pdo->query("SHOW COLUMNS FROM note LIKE 'note_rattrapage'")->fetch();
    $retake = $hasRetake ? 'n.note_rattrapage' : 'NULL AS note_rattrapage';
    $query = $pdo->prepare(
        'SELECT n.id_note, n.id_mat, n.id_groupe, n.semestre, n.note, n.note_cours, n.note_td, n.note_tp, '
        . $retake . ',
                m.code_ue, m.description_ue, m.code_ecue, m.description_ecue, m.coeff, m.credit, m.regime, m.volume_tp
         FROM note n
         JOIN matiere m ON m.id_mat = n.id_mat
         WHERE n.id_etudiant = ?
         ORDER BY n.semestre, m.code_ue, m.code_ecue'
    );
    $query->execute([$studentId]);
    return $query->fetchAll();
}

function result_student_average(int $studentId, ?int $programId = null): ?float
{
    $marks = result_student_marks($studentId);
    $weighted = 0.0;
    $coeff = 0.0;
    $credits = 0.0;
    $sum = 0.0;
    $count = 0;
    foreach ($marks as $mark) {
        $note = calculated_note($mark);
        if ($note === null) {
            continue;
        }
        $weighted += (float) $note * (float) $mark['coeff'];
        $coeff += (float) $mark['coeff'];
        $credits += (float) $mark['credit'];
        $sum += (float) $note;
        $count++;
    }
    if ($count < 1) {
        return null;
    }
    $general = institution_general_average($weighted, $coeff, $credits, $count, $sum);
    if ($general !== null) {
        return $general;
    }
    return $coeff > 0 ? round($weighted / $coeff, 2) : null;
}

/**
 * Expected (group, subject) pairs for an active student via Cours group affectations.
 *
 * @return list<array<string, mixed>>
 */
function result_expected_subjects_for_student(int $studentId): array
{
    $pdo = db();
    $query = $pdo->prepare(
        'SELECT DISTINCT g.id_groupe, g.semestre AS groupe_semestre, g.numero AS groupe_numero,
                m.id_mat, m.code_ecue, m.description_ecue, m.semestre
         FROM groupe_etudiant ge
         JOIN groupe g ON g.id_groupe = ge.id_groupe AND g.type_groupe = "Cours"
         JOIN enseignant_affectation a ON a.id_groupe = g.id_groupe
         JOIN matiere m ON m.id_mat = a.id_mat AND m.semestre = g.semestre
         WHERE ge.id_etudiant = ?
         ORDER BY m.semestre, m.code_ecue'
    );
    $query->execute([$studentId]);
    return $query->fetchAll();
}

/**
 * Students missing at least one expected mark.
 *
 * @return list<array<string, mixed>>
 */
function result_students_missing_marks(): array
{
    $pdo = db();
    $query = $pdo->query(
        'SELECT e.id_etudiant, e.nom, e.prenom, e.cin_passeport, e.niveau, e.num_inscription,
                s.nom_spec, s.id_program,
                g.id_groupe, g.numero AS groupe_numero, g.semestre AS groupe_semestre,
                m.id_mat, m.code_ecue, m.description_ecue, m.semestre AS matiere_semestre
         FROM etudiant e
         JOIN specialite s ON s.id_spec = e.id_spec
         JOIN groupe_etudiant ge ON ge.id_etudiant = e.id_etudiant
         JOIN groupe g ON g.id_groupe = ge.id_groupe AND g.type_groupe = "Cours"
         JOIN enseignant_affectation a ON a.id_groupe = g.id_groupe
         JOIN matiere m ON m.id_mat = a.id_mat AND m.semestre = g.semestre
         LEFT JOIN note n ON n.id_etudiant = e.id_etudiant AND n.id_mat = m.id_mat AND n.semestre = m.semestre
           AND (n.note IS NOT NULL OR n.note_cours IS NOT NULL OR n.note_td IS NOT NULL OR n.note_tp IS NOT NULL OR n.note_rattrapage IS NOT NULL)
         WHERE e.statut = "actif" AND n.id_note IS NULL
         ORDER BY e.nom, e.prenom, m.semestre, m.code_ecue'
    );
    $rows = $query->fetchAll();
    $byStudent = [];
    foreach ($rows as $row) {
        $id = (int) $row['id_etudiant'];
        if (!isset($byStudent[$id])) {
            $byStudent[$id] = [
                'id_etudiant' => $id,
                'nom' => (string) $row['nom'],
                'prenom' => (string) $row['prenom'],
                'cin_passeport' => (string) $row['cin_passeport'],
                'niveau' => (string) $row['niveau'],
                'nom_spec' => (string) $row['nom_spec'],
                'groupe' => 'Cours ' . (string) $row['groupe_numero'] . ' · ' . (string) $row['groupe_semestre'],
                'id_groupe' => (int) $row['id_groupe'],
                'missing' => [],
            ];
        }
        $byStudent[$id]['missing'][] = [
            'id_mat' => (int) $row['id_mat'],
            'code_ecue' => (string) $row['code_ecue'],
            'description_ecue' => (string) $row['description_ecue'],
            'semestre' => (string) $row['matiere_semestre'],
            'id_groupe' => (int) $row['id_groupe'],
        ];
    }
    return array_values($byStudent);
}

function result_row_for_student(int $studentId, string $year = ''): ?array
{
    result_publication_ensure();
    $year = $year !== '' ? $year : result_active_year();
    $query = db()->prepare('SELECT * FROM etudiant_resultat WHERE id_etudiant = ? AND annee_scolaire = ? LIMIT 1');
    $query->execute([$studentId, $year]);
    $row = $query->fetch();
    return $row ?: null;
}

/**
 * Pending rachat: moyenne < seuil, not yet decided.
 *
 * @return list<array<string, mixed>>
 */
function result_pending_rachat_students(string $year = ''): array
{
    result_publication_ensure();
    $year = $year !== '' ? $year : result_active_year();
    $pdo = db();
    $students = $pdo->query(
        'SELECT e.id_etudiant, e.nom, e.prenom, e.cin_passeport, e.niveau, e.num_inscription,
                s.nom_spec, s.id_program,
                (SELECT CONCAT("Cours ", g.numero, " · ", g.semestre)
                   FROM groupe_etudiant ge
                   JOIN groupe g ON g.id_groupe = ge.id_groupe AND g.type_groupe = "Cours"
                  WHERE ge.id_etudiant = e.id_etudiant
                  ORDER BY g.semestre, g.numero LIMIT 1) AS groupe_label
         FROM etudiant e
         JOIN specialite s ON s.id_spec = e.id_spec
         WHERE e.statut = "actif"
         ORDER BY e.nom, e.prenom'
    )->fetchAll();

    $pending = [];
    foreach ($students as $student) {
        $id = (int) $student['id_etudiant'];
        $programId = isset($student['id_program']) ? (int) $student['id_program'] : null;
        $threshold = result_pass_threshold($programId);
        $average = result_student_average($id, $programId);
        if ($average === null || $average >= $threshold) {
            continue;
        }
        $existing = result_row_for_student($id, $year);
        $decision = (string) ($existing['decision'] ?? 'en_attente');
        if (in_array($decision, ['admis_par_rachat', 'refusee'], true)) {
            continue;
        }
        $pending[] = [
            'id_etudiant' => $id,
            'nom' => (string) $student['nom'],
            'prenom' => (string) $student['prenom'],
            'cin_passeport' => (string) $student['cin_passeport'],
            'niveau' => (string) $student['niveau'],
            'nom_spec' => (string) $student['nom_spec'],
            'groupe' => (string) ($student['groupe_label'] ?? '—'),
            'moyenne' => $average,
            'threshold' => $threshold,
            'decision' => $decision,
        ];
    }
    return $pending;
}

function result_pending_rachat_count(string $year = ''): int
{
    return count(result_pending_rachat_students($year));
}

function result_save_decision(int $studentId, string $decision, string $year = ''): void
{
    if (!in_array($decision, ['admis_par_rachat', 'refusee'], true)) {
        throw new InvalidArgumentException('Décision invalide.');
    }
    result_publication_ensure();
    $year = $year !== '' ? $year : result_active_year();
    $pdo = db();
    $student = $pdo->prepare('SELECT e.id_etudiant, s.id_program FROM etudiant e JOIN specialite s ON s.id_spec = e.id_spec WHERE e.id_etudiant = ? AND e.statut = "actif" LIMIT 1');
    $student->execute([$studentId]);
    $row = $student->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Étudiant introuvable.');
    }
    $programId = isset($row['id_program']) ? (int) $row['id_program'] : null;
    $average = result_student_average($studentId, $programId);
    $threshold = result_pass_threshold($programId);
    if ($average === null) {
        throw new InvalidArgumentException('Impossible de décider sans moyenne calculable.');
    }
    if ($average >= $threshold) {
        throw new InvalidArgumentException('Cet étudiant est déjà au-dessus du seuil (admis automatique).');
    }

    $adminId = (int) ($_SESSION['admin_id'] ?? 0) ?: null;
    $existing = result_row_for_student($studentId, $year);
    if ($existing) {
        $pdo->prepare(
            'UPDATE etudiant_resultat
             SET moyenne = ?, decision = ?, decided_at = NOW(), decided_by = ?
             WHERE id_etudiant = ? AND annee_scolaire = ?'
        )->execute([$average, $decision, $adminId, $studentId, $year]);
    } else {
        $pdo->prepare(
            'INSERT INTO etudiant_resultat (id_etudiant, annee_scolaire, moyenne, decision, decided_at, decided_by)
             VALUES (?, ?, ?, ?, NOW(), ?)'
        )->execute([$studentId, $year, $average, $decision, $adminId]);
    }
}

/**
 * Students with moyenne < seuil who still need a rachat decision.
 *
 * @return list<array<string, mixed>>
 */
function result_undecided_below_threshold(string $year = ''): array
{
    return result_pending_rachat_students($year);
}

function result_publish_all(string $year = ''): array
{
    result_publication_ensure();
    $year = $year !== '' ? $year : result_active_year();
    $missing = result_students_missing_marks();
    if ($missing !== []) {
        throw new InvalidArgumentException('Des notes manquent encore pour ' . count($missing) . ' étudiant(s).');
    }
    $undecided = result_undecided_below_threshold($year);
    if ($undecided !== []) {
        throw new InvalidArgumentException('Décidez d’abord le rachat / refus pour ' . count($undecided) . ' étudiant(s) sous le seuil.');
    }

    $pdo = db();
    $students = $pdo->query(
        'SELECT e.id_etudiant, e.nom, e.prenom, s.id_program
         FROM etudiant e
         JOIN specialite s ON s.id_spec = e.id_spec
         WHERE e.statut = "actif"
         ORDER BY e.nom, e.prenom'
    )->fetchAll();

    $adminId = (int) ($_SESSION['admin_id'] ?? 0) ?: null;
    $sent = 0;
    $pdo->beginTransaction();
    try {
        foreach ($students as $student) {
            $id = (int) $student['id_etudiant'];
            $programId = isset($student['id_program']) ? (int) $student['id_program'] : null;
            $threshold = result_pass_threshold($programId);
            $average = result_student_average($id, $programId);
            $marks = result_student_marks($id);
            $expected = result_expected_subjects_for_student($id);
            if ($expected === [] && $marks === []) {
                continue;
            }
            $existing = result_row_for_student($id, $year);
            $decision = (string) ($existing['decision'] ?? 'en_attente');

            if ($average !== null && $average >= $threshold) {
                $decision = 'admis';
            } elseif (!in_array($decision, ['admis_par_rachat', 'refusee'], true)) {
                throw new InvalidArgumentException(
                    'Décision manquante pour ' . trim((string) $student['prenom'] . ' ' . (string) $student['nom'])
                );
            }

            $payload = json_encode([
                'moyenne' => $average,
                'decision' => $decision,
                'threshold' => $threshold,
                'marks' => $marks,
                'published_at' => date('c'),
            ], JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                throw new RuntimeException('Impossible de sérialiser le relevé.');
            }

            if ($existing) {
                $pdo->prepare(
                    'UPDATE etudiant_resultat
                     SET moyenne = ?, decision = ?, published_at = NOW(), transcript_payload = ?,
                         decided_at = CASE WHEN ? = "admis" AND decision <> "admis" THEN NOW() ELSE decided_at END,
                         decided_by = CASE WHEN ? = "admis" THEN ? ELSE decided_by END
                     WHERE id_etudiant = ? AND annee_scolaire = ?'
                )->execute([$average, $decision, $payload, $decision, $decision, $adminId, $id, $year]);
            } else {
                $pdo->prepare(
                    'INSERT INTO etudiant_resultat (id_etudiant, annee_scolaire, moyenne, decision, decided_at, decided_by, published_at, transcript_payload)
                     VALUES (?, ?, ?, ?, NOW(), ?, NOW(), ?)'
                )->execute([$id, $year, $average, $decision, $adminId, $payload]);
            }

            student_notify(
                $pdo,
                $id,
                'Résultats publiés — ' . $year,
                'Vos résultats sont disponibles. Décision : ' . result_decision_label($decision)
                . ($average !== null ? ' · Moyenne : ' . number_format($average, 2, ',', '') . '/20' : '')
                . '. Vous pouvez consulter et télécharger votre relevé de notes.',
                'dashboard.php?panel=notes'
            );
            $sent++;
        }

        $campagne = result_campagne($year);
        if ((int) ($campagne['id_campagne'] ?? 0) > 0) {
            $pdo->prepare(
                'UPDATE resultat_campagne SET status = "publiee", published_at = NOW(), published_by = ? WHERE annee_scolaire = ?'
            )->execute([$adminId, $year]);
        } else {
            $pdo->prepare(
                'INSERT INTO resultat_campagne (annee_scolaire, status, published_at, published_by)
                 VALUES (?, "publiee", NOW(), ?)'
            )->execute([$year, $adminId]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    academic_policy_note('envoyer_resultats', $year . ' · ' . $sent . ' relevé(s)');
    return ['sent' => $sent, 'year' => $year];
}

function result_published_for_student(int $studentId, string $year = ''): ?array
{
    $row = result_row_for_student($studentId, $year);
    if (!$row || empty($row['published_at']) || empty($row['transcript_payload'])) {
        return null;
    }
    $payload = json_decode((string) $row['transcript_payload'], true);
    if (!is_array($payload)) {
        return null;
    }
    return [
        'row' => $row,
        'payload' => $payload,
        'moyenne' => isset($payload['moyenne']) ? (float) $payload['moyenne'] : ($row['moyenne'] !== null ? (float) $row['moyenne'] : null),
        'decision' => (string) ($payload['decision'] ?? $row['decision']),
        'marks' => is_array($payload['marks'] ?? null) ? $payload['marks'] : [],
    ];
}
