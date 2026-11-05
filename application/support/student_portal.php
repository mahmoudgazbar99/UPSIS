<?php
declare(strict_types=1);

function student_portal_ensure(PDO $pdo): void
{
    static $ready = false;
    if ($ready || app_schema_is_ready('student_portal')) {
        $ready = true;
        return;
    }
    $ready = true;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_group_request (
          id_request INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_etudiant INT UNSIGNED NOT NULL,
          request_type VARCHAR(20) NOT NULL,
          id_groupe_actuel INT UNSIGNED NOT NULL,
          id_groupe_cible INT UNSIGNED NOT NULL,
          id_etudiant_cible INT UNSIGNED NULL,
          commentaire TEXT NULL,
          statut VARCHAR(32) NOT NULL DEFAULT 'en_attente',
          admin_comment TEXT NULL,
          processed_by INT UNSIGNED NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          processed_at TIMESTAMP NULL DEFAULT NULL,
          PRIMARY KEY (id_request),
          KEY idx_group_request_student (id_etudiant, statut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_appointment (
          id_appointment INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_etudiant INT UNSIGNED NOT NULL,
          requested_date DATE NOT NULL,
          requested_time TIME NOT NULL,
          motif TEXT NOT NULL,
          statut VARCHAR(32) NOT NULL DEFAULT 'en_attente',
          response_date DATE NULL,
          response_time TIME NULL,
          admin_comment TEXT NULL,
          processed_by INT UNSIGNED NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          processed_at TIMESTAMP NULL DEFAULT NULL,
          PRIMARY KEY (id_appointment),
          KEY idx_appointment_student (id_etudiant, statut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_complaint (
          id_complaint INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_etudiant INT UNSIGNED NOT NULL,
          categorie VARCHAR(32) NOT NULL,
          description TEXT NOT NULL,
          statut VARCHAR(32) NOT NULL DEFAULT 'en_attente',
          admin_response TEXT NULL,
          processed_by INT UNSIGNED NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          processed_at TIMESTAMP NULL DEFAULT NULL,
          PRIMARY KEY (id_complaint),
          KEY idx_complaint_student (id_etudiant, statut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    app_schema_mark_ready('student_portal');
}

function student_portal_pending_count(PDO $pdo): int
{
    student_portal_ensure($pdo);
    $groups = (int) $pdo->query('SELECT COUNT(*) FROM student_group_request WHERE statut = "en_attente"')->fetchColumn();
    $appointments = (int) $pdo->query('SELECT COUNT(*) FROM student_appointment WHERE statut = "en_attente"')->fetchColumn();
    $complaints = (int) $pdo->query('SELECT COUNT(*) FROM student_complaint WHERE statut = "en_attente"')->fetchColumn();
    return $groups + $appointments + $complaints;
}

function student_portal_pending_stamp(PDO $pdo): string
{
    student_portal_ensure($pdo);
    $group = (int) $pdo->query('SELECT COALESCE(MAX(id_request), 0) FROM student_group_request WHERE statut = "en_attente"')->fetchColumn();
    $appointment = (int) $pdo->query('SELECT COALESCE(MAX(id_appointment), 0) FROM student_appointment WHERE statut = "en_attente"')->fetchColumn();
    $complaint = (int) $pdo->query('SELECT COALESCE(MAX(id_complaint), 0) FROM student_complaint WHERE statut = "en_attente"')->fetchColumn();
    return student_portal_pending_count($pdo) . ':' . $group . ':' . $appointment . ':' . $complaint;
}

function student_portal_status_label(string $status): string
{
    return match ($status) {
        'en_attente' => 'En attente de traitement',
        'validee' => 'Validée',
        'refusee' => 'Refusée',
        'reprogrammee' => 'Reprogrammée',
        default => 'Demande soumise',
    };
}

function student_portal_plan(PDO $pdo, int $studentId): array
{
    $query = $pdo->prepare(
        'SELECT m.semestre, m.code_ue, m.description_ue, m.code_ecue, m.description_ecue,
                m.coefficient_ecue, m.volume_cours, m.volume_td, m.volume_tp,
                ens.nom AS prof_nom, ens.prenom AS prof_prenom
         FROM groupe_etudiant ge
         INNER JOIN emploi_temps et ON et.id_groupe = ge.id_groupe
         INNER JOIN matiere m ON m.id_mat = et.id_mat
         LEFT JOIN enseignant ens ON ens.id_enseignant = et.id_enseignant
         WHERE ge.id_etudiant = ?
         GROUP BY m.id_mat, m.semestre, m.code_ue, m.description_ue, m.code_ecue, m.description_ecue,
                  m.coefficient_ecue, m.volume_cours, m.volume_td, m.volume_tp, ens.id_enseignant, ens.nom, ens.prenom
         ORDER BY m.semestre, m.code_ue, m.code_ecue'
    );
    $query->execute([$studentId]);
    $grouped = [];
    foreach ($query->fetchAll() as $row) {
        $key = (string) $row['semestre'] . '|' . (string) $row['code_ecue'];
        $name = trim((string) ($row['prof_prenom'] ?? '') . ' ' . (string) ($row['prof_nom'] ?? ''));
        if (!isset($grouped[$key])) {
            $row['teachers'] = [];
            $grouped[$key] = $row;
        }
        if ($name !== '' && !in_array($name, $grouped[$key]['teachers'], true)) {
            $grouped[$key]['teachers'][] = $name;
        }
    }
    return array_values($grouped);
}

function student_portal_absences(PDO $pdo, int $studentId): array
{
    $query = $pdo->prepare(
        'SELECT p.date_seance, p.duree_heures, p.statut,
                m.code_ue, m.description_ecue, m.semestre,
                ens.nom AS prof_nom, ens.prenom AS prof_prenom
         FROM presence_etudiant p
         LEFT JOIN matiere m ON m.id_mat = p.id_mat
         LEFT JOIN enseignant ens ON ens.id_enseignant = p.id_enseignant
         WHERE p.id_etudiant = ? AND p.statut NOT IN ("present", "présent", "Présent")
         ORDER BY p.date_seance DESC
         LIMIT 200'
    );
    $query->execute([$studentId]);
    return $query->fetchAll();
}

function student_portal_current_group(PDO $pdo, int $studentId): ?array
{
    $query = $pdo->prepare(
        'SELECT g.id_groupe, g.semestre, g.type_groupe, g.numero, g.capacite
         FROM groupe_etudiant ge
         INNER JOIN groupe g ON g.id_groupe = ge.id_groupe
         WHERE ge.id_etudiant = ?
         ORDER BY g.semestre, g.type_groupe, g.numero
         LIMIT 1'
    );
    $query->execute([$studentId]);
    $row = $query->fetch();
    return $row ?: null;
}

function student_portal_target_groups(PDO $pdo, int $studentId, int $specId, int $currentGroupId, bool $requireSeat = true): array
{
    $query = $pdo->prepare(
        'SELECT g.id_groupe, g.semestre, g.type_groupe, g.numero, g.capacite,
                (SELECT COUNT(*) FROM groupe_etudiant ge2 WHERE ge2.id_groupe = g.id_groupe) AS inscrits
         FROM groupe g
         WHERE g.id_groupe <> ?
           AND (
             NOT EXISTS (SELECT 1 FROM groupe_etudiant ge3 WHERE ge3.id_groupe = g.id_groupe)
             OR NOT EXISTS (
               SELECT 1 FROM groupe_etudiant ge4
               INNER JOIN etudiant e ON e.id_etudiant = ge4.id_etudiant
               WHERE ge4.id_groupe = g.id_groupe AND e.id_spec <> ?
             )
           )
         ORDER BY g.semestre, g.type_groupe, g.numero'
    );
    $query->execute([$currentGroupId, $specId]);
    $rows = $query->fetchAll();
    if (!$requireSeat) {
        return $rows;
    }
    return array_values(array_filter($rows, static function (array $row): bool {
        return (int) $row['inscrits'] < (int) $row['capacite'];
    }));
}

function student_portal_swap_students(PDO $pdo, array $groupIds, int $specId, int $selfId): array
{
    $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
    if ($groupIds === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($groupIds), '?'));
    $query = $pdo->prepare(
        'SELECT e.id_etudiant, e.nom, e.prenom, ge.id_groupe
         FROM groupe_etudiant ge
         INNER JOIN etudiant e ON e.id_etudiant = ge.id_etudiant
         WHERE ge.id_groupe IN (' . $marks . ') AND e.id_spec = ? AND e.id_etudiant <> ? AND e.statut = "actif"
         ORDER BY e.nom, e.prenom'
    );
    $query->execute([...$groupIds, $specId, $selfId]);
    return $query->fetchAll();
}

function student_portal_group_students(PDO $pdo, int $groupId, int $specId, int $selfId): array
{
    $query = $pdo->prepare(
        'SELECT e.id_etudiant, e.nom, e.prenom
         FROM groupe_etudiant ge
         INNER JOIN etudiant e ON e.id_etudiant = ge.id_etudiant
         WHERE ge.id_groupe = ? AND e.id_spec = ? AND e.id_etudiant <> ? AND e.statut = "actif"
         ORDER BY e.nom, e.prenom'
    );
    $query->execute([$groupId, $specId, $selfId]);
    return $query->fetchAll();
}

function student_portal_contacts(PDO $pdo, int $studentId): array
{
    $query = $pdo->prepare(
        'SELECT DISTINCT ens.nom, ens.prenom, ens.email, m.description_ecue
         FROM groupe_etudiant ge
         INNER JOIN enseignant_affectation a ON a.id_groupe = ge.id_groupe
         INNER JOIN enseignant ens ON ens.id_enseignant = a.id_enseignant
         INNER JOIN matiere m ON m.id_mat = a.id_mat
         WHERE ge.id_etudiant = ?
         ORDER BY ens.nom, ens.prenom, m.description_ecue'
    );
    $query->execute([$studentId]);
    return $query->fetchAll();
}

function student_portal_requests(PDO $pdo, int $studentId): array
{
    $groups = $pdo->prepare('SELECT id_request, request_type, id_groupe_actuel, id_groupe_cible, id_etudiant_cible, commentaire, statut, admin_comment, created_at, processed_at FROM student_group_request WHERE id_etudiant = ? ORDER BY created_at DESC LIMIT 50');
    $groups->execute([$studentId]);
    $appointments = $pdo->prepare('SELECT id_appointment, requested_date, requested_time, motif, statut, response_date, response_time, admin_comment, created_at, processed_at FROM student_appointment WHERE id_etudiant = ? ORDER BY created_at DESC LIMIT 50');
    $appointments->execute([$studentId]);
    $complaints = $pdo->prepare('SELECT id_complaint, categorie, description, statut, admin_response, created_at, processed_at FROM student_complaint WHERE id_etudiant = ? ORDER BY created_at DESC LIMIT 50');
    $complaints->execute([$studentId]);
    return ['groups' => $groups->fetchAll(), 'appointments' => $appointments->fetchAll(), 'complaints' => $complaints->fetchAll()];
}

function student_portal_submit_group(PDO $pdo, int $studentId, int $specId, string $type, int $targetGroup, int $partnerId, string $comment): string
{
    student_portal_ensure($pdo);
    $current = student_portal_current_group($pdo, $studentId);
    if ($current === null) {
        return 'Aucun groupe n’est enregistré sur votre dossier.';
    }
    if (!in_array($type, ['changement', 'permutation'], true)) {
        return 'Type de demande inconnu.';
    }
    $okGroup = false;
    foreach (student_portal_target_groups($pdo, $studentId, $specId, (int) $current['id_groupe'], $type === 'changement') as $group) {
        if ((int) $group['id_groupe'] === $targetGroup) {
            $okGroup = true;
        }
    }
    if (!$okGroup) {
        return 'Ce groupe n’est pas ouvert pour votre spécialité.';
    }
    if ($type === 'permutation') {
        $okPerson = false;
        foreach (student_portal_group_students($pdo, $targetGroup, $specId, $studentId) as $person) {
            if ((int) $person['id_etudiant'] === $partnerId) {
                $okPerson = true;
            }
        }
        if (!$okPerson) {
            return 'Choisissez un étudiant du groupe demandé.';
        }
    } else {
        $partnerId = 0;
    }
    $pending = $pdo->prepare('SELECT COUNT(*) FROM student_group_request WHERE id_etudiant = ? AND request_type = ? AND statut = "en_attente"');
    $pending->execute([$studentId, $type]);
    if ((int) $pending->fetchColumn() > 0) {
        return 'Une demande du même type est déjà en attente.';
    }
    $insert = $pdo->prepare('INSERT INTO student_group_request (id_etudiant, request_type, id_groupe_actuel, id_groupe_cible, id_etudiant_cible, commentaire, statut) VALUES (?, ?, ?, ?, NULLIF(?, 0), ?, "en_attente")');
    $insert->execute([$studentId, $type, (int) $current['id_groupe'], $targetGroup, $partnerId, mb_substr($comment, 0, 1000)]);
    student_notify($pdo, $studentId, 'Demande de groupe envoyée', 'Votre demande a été soumise et attend l’administration.', 'dashboard.php?panel=groupe');
    return '';
}

function student_portal_submit_appointment(PDO $pdo, int $studentId, string $date, string $time, string $motif): string
{
    student_portal_ensure($pdo);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time) || $date < date('Y-m-d') || mb_strlen(trim($motif)) < 5) {
        return 'Choisissez une date à venir, une heure, et un motif.';
    }
    $insert = $pdo->prepare('INSERT INTO student_appointment (id_etudiant, requested_date, requested_time, motif, statut) VALUES (?, ?, ?, ?, "en_attente")');
    $insert->execute([$studentId, $date, $time . ':00', mb_substr(trim($motif), 0, 2000)]);
    student_notify($pdo, $studentId, 'Demande de rendez-vous envoyée', 'Votre demande de consultation a été soumise. Le motif reste limité à l’administration.', 'dashboard.php?panel=psy');
    return '';
}

function student_portal_submit_complaint(PDO $pdo, int $studentId, string $category, string $description): string
{
    student_portal_ensure($pdo);
    if (!in_array($category, ['infrastructure', 'absence', 'note', 'module'], true) || mb_strlen(trim($description)) < 5) {
        return 'Choisissez un type et décrivez la réclamation.';
    }
    $insert = $pdo->prepare('INSERT INTO student_complaint (id_etudiant, categorie, description, statut) VALUES (?, ?, ?, "en_attente")');
    $insert->execute([$studentId, $category, mb_substr(trim($description), 0, 4000)]);
    student_notify($pdo, $studentId, 'Réclamation envoyée', 'Votre réclamation a été soumise.', 'dashboard.php?panel=reclamation');
    return '';
}

function student_portal_update_profile(PDO $pdo, int $studentId, string $email, string $phone, string $address): string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($phone) < 6 || mb_strlen(trim($address)) < 3) {
        return 'Vérifiez l’e-mail, le téléphone et l’adresse.';
    }
    $update = $pdo->prepare('UPDATE etudiant SET email = ?, num_tel = ?, adresse = ? WHERE id_etudiant = ?');
    $update->execute([mb_substr($email, 0, 150), mb_substr($phone, 0, 30), mb_substr(trim($address), 0, 255), $studentId]);
    student_notify($pdo, $studentId, 'Profil mis à jour', 'Vos coordonnées ont été enregistrées. La spécialité et le groupe ne changent pas ici.', 'dashboard.php?panel=profil');
    return '';
}

function student_portal_admin_decide(
    PDO $pdo,
    string $kind,
    int $id,
    string $decision,
    string $comment,
    int $adminId,
    string $responseDate = '',
    string $responseTime = ''
): string {
    student_portal_ensure($pdo);
    if (!in_array($decision, ['validee', 'refusee', 'reprogrammee'], true)) {
        return 'Décision inconnue.';
    }
    if ($kind === 'groupe') {
        $row = $pdo->prepare('SELECT * FROM student_group_request WHERE id_request = ? AND statut = "en_attente"');
        $row->execute([$id]);
        $request = $row->fetch();
        if (!$request) {
            return 'Demande introuvable.';
        }
        $pdo->beginTransaction();
        try {
            if ($decision === 'validee' && (string) $request['request_type'] === 'changement') {
                $cap = $pdo->prepare('SELECT capacite, (SELECT COUNT(*) FROM groupe_etudiant WHERE id_groupe = g.id_groupe) AS inscrits FROM groupe g WHERE id_groupe = ?');
                $cap->execute([(int) $request['id_groupe_cible']]);
                $group = $cap->fetch();
                if (!$group || (int) $group['inscrits'] >= (int) $group['capacite']) {
                    $pdo->rollBack();
                    return 'Le groupe n’a plus de place.';
                }
                $pdo->prepare('DELETE FROM groupe_etudiant WHERE id_etudiant = ? AND id_groupe = ?')->execute([(int) $request['id_etudiant'], (int) $request['id_groupe_actuel']]);
                $pdo->prepare('INSERT INTO groupe_etudiant (id_groupe, id_etudiant) VALUES (?, ?)')->execute([(int) $request['id_groupe_cible'], (int) $request['id_etudiant']]);
            }
            if ($decision === 'validee' && (string) $request['request_type'] === 'permutation') {
                $a = (int) $request['id_etudiant'];
                $b = (int) $request['id_etudiant_cible'];
                $ga = (int) $request['id_groupe_actuel'];
                $gb = (int) $request['id_groupe_cible'];
                $still = $pdo->prepare('SELECT COUNT(*) FROM groupe_etudiant WHERE (id_etudiant = ? AND id_groupe = ?) OR (id_etudiant = ? AND id_groupe = ?)');
                $still->execute([$a, $ga, $b, $gb]);
                if ((int) $still->fetchColumn() !== 2) {
                    $pdo->rollBack();
                    return 'Les deux étudiants ne sont plus dans les groupes demandés.';
                }
                $pdo->prepare('DELETE FROM groupe_etudiant WHERE (id_etudiant = ? AND id_groupe = ?) OR (id_etudiant = ? AND id_groupe = ?)')->execute([$a, $ga, $b, $gb]);
                $pdo->prepare('INSERT INTO groupe_etudiant (id_groupe, id_etudiant) VALUES (?, ?)')->execute([$gb, $a]);
                $pdo->prepare('INSERT INTO groupe_etudiant (id_groupe, id_etudiant) VALUES (?, ?)')->execute([$ga, $b]);
            }
            $pdo->prepare('UPDATE student_group_request SET statut = ?, admin_comment = ?, processed_by = ?, processed_at = CURRENT_TIMESTAMP WHERE id_request = ?')->execute([$decision === 'validee' ? 'validee' : 'refusee', mb_substr($comment, 0, 1000), $adminId, $id]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return 'La modification du groupe n’a pas abouti.';
        }
        student_notify($pdo, (int) $request['id_etudiant'], $decision === 'validee' ? 'Demande de groupe acceptée' : 'Demande de groupe refusée', $comment !== '' ? $comment : ($decision === 'validee' ? 'Votre groupe a été mis à jour.' : 'Votre groupe reste inchangé.'), 'dashboard.php?panel=groupe');
        if ($decision === 'validee' && (string) $request['request_type'] === 'permutation' && (int) $request['id_etudiant_cible'] > 0) {
            student_notify($pdo, (int) $request['id_etudiant_cible'], 'Permutation de groupe', 'Votre groupe a été échangé avec un autre étudiant.', 'dashboard.php?panel=groupe');
        }
        return '';
    }
    if ($kind === 'psy') {
        $status = match ($decision) {
            'validee' => 'validee',
            'reprogrammee' => 'reprogrammee',
            default => 'refusee',
        };
        $newDate = '';
        $newTime = '';
        if ($decision === 'reprogrammee') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $responseDate) || !preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $responseTime)) {
                return 'Choisissez une date et une heure pour reprogrammer.';
            }
            $newDate = $responseDate;
            $newTime = strlen($responseTime) === 5 ? $responseTime . ':00' : $responseTime;
            $stamp = $newDate . ' ' . substr($newTime, 0, 5);
            if ($comment === '') {
                $comment = 'Nouveau créneau : ' . $stamp;
            } elseif (!str_contains($comment, $stamp)) {
                $comment = 'Nouveau créneau : ' . $stamp . ' — ' . $comment;
            }
        }
        $pdo->prepare(
            'UPDATE student_appointment
             SET statut = ?, admin_comment = ?, response_date = ?, response_time = ?, processed_by = ?, processed_at = CURRENT_TIMESTAMP
             WHERE id_appointment = ? AND statut = "en_attente"'
        )->execute([
            $status,
            mb_substr($comment, 0, 1000),
            $newDate !== '' ? $newDate : null,
            $newTime !== '' ? $newTime : null,
            $adminId,
            $id,
        ]);
        $who = $pdo->prepare('SELECT id_etudiant FROM student_appointment WHERE id_appointment = ?');
        $who->execute([$id]);
        $student = (int) $who->fetchColumn();
        $text = match ($decision) {
            'validee' => 'Votre rendez-vous psychologique a été confirmé.',
            'reprogrammee' => 'Votre rendez-vous a été reprogrammé'
                . ($newDate !== '' ? ' au ' . $newDate . ' à ' . substr($newTime, 0, 5) : '')
                . ($comment !== '' ? '. ' . $comment : '.'),
            default => 'Votre demande de rendez-vous a été refusée.',
        };
        if ($student > 0) {
            student_notify($pdo, $student, 'Rendez-vous psychologique', $text, 'dashboard.php?panel=psy');
        }
        return '';
    }
    if ($kind === 'reclamation') {
        $response = trim($comment) !== '' ? trim($comment) : 'Nous vous contacterons prochainement.';
        $pdo->prepare('UPDATE student_complaint SET statut = "validee", admin_response = ?, processed_by = ?, processed_at = CURRENT_TIMESTAMP WHERE id_complaint = ? AND statut = "en_attente"')->execute([mb_substr($response, 0, 4000), $adminId, $id]);
        $who = $pdo->prepare('SELECT id_etudiant FROM student_complaint WHERE id_complaint = ?');
        $who->execute([$id]);
        $student = (int) $who->fetchColumn();
        if ($student > 0) {
            student_notify($pdo, $student, 'Réponse à votre réclamation', $response, 'dashboard.php?panel=reclamation');
        }
        return '';
    }
    return 'Dossier inconnu.';
}
