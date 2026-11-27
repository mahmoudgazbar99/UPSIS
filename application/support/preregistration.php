<?php
declare(strict_types=1);

/**
 * PA-SAE-01 — Préinscription annuelle des étudiants déjà inscrits.
 * SA-01 engagement individuel · SA-02 registre · SA-03 état prévisionnel.
 * Distinct from Admissions (nouveaux candidats / بلاغ inscription finale).
 */

function prereg_ensure(): void
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
    prereg_tables($pdo);
    prereg_seed($pdo);
    try {
        $pdo->prepare('INSERT IGNORE INTO schema_migration (version) VALUES (?)')->execute(['2026_11_24_prereg_pa_sae_01']);
    } catch (Throwable $ignored) {
    }
}

function prereg_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS prereg_campaign (
          id_campaign INT UNSIGNED NOT NULL AUTO_INCREMENT,
          label VARCHAR(160) NOT NULL,
          current_year_label VARCHAR(20) NOT NULL DEFAULT '',
          next_year_label VARCHAR(20) NOT NULL DEFAULT '',
          opens_at DATE DEFAULT NULL,
          closes_at DATE DEFAULT NULL,
          advance_amount DECIMAL(12,2) NOT NULL DEFAULT 200.00,
          status ENUM('draft','open','closed','archived') NOT NULL DEFAULT 'draft',
          notes TEXT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_campaign)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS prereg_commitment (
          id_commitment INT UNSIGNED NOT NULL AUTO_INCREMENT,
          id_campaign INT UNSIGNED NOT NULL,
          id_etudiant INT UNSIGNED NOT NULL,
          num_inscription VARCHAR(40) NOT NULL DEFAULT '',
          nom VARCHAR(80) NOT NULL DEFAULT '',
          prenom VARCHAR(80) NOT NULL DEFAULT '',
          current_level VARCHAR(40) NOT NULL DEFAULT '',
          current_group VARCHAR(40) NOT NULL DEFAULT '',
          intended_level VARCHAR(40) NOT NULL DEFAULT '',
          current_year_label VARCHAR(20) NOT NULL DEFAULT '',
          commitment_text_accepted TINYINT(1) NOT NULL DEFAULT 0,
          submitted_at TIMESTAMP NULL,
          advance_paid TINYINT(1) NOT NULL DEFAULT 0,
          advance_paid_at DATE DEFAULT NULL,
          advance_method VARCHAR(40) NOT NULL DEFAULT '',
          advance_receipt_no VARCHAR(80) NOT NULL DEFAULT '',
          advance_amount DECIMAL(12,2) NOT NULL DEFAULT 200.00,
          receipt_stored VARCHAR(80) NOT NULL DEFAULT '',
          notes VARCHAR(500) NOT NULL DEFAULT '',
          staff_notes VARCHAR(500) NOT NULL DEFAULT '',
          status VARCHAR(40) NOT NULL DEFAULT 'submitted',
          verified_by_affairs VARCHAR(120) NOT NULL DEFAULT '',
          verified_by_finance VARCHAR(120) NOT NULL DEFAULT '',
          verified_at TIMESTAMP NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id_commitment),
          UNIQUE KEY uq_prereg_student_campaign (id_campaign, id_etudiant),
          KEY idx_prereg_camp (id_campaign, advance_paid, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS prereg_setting (
          setting_key VARCHAR(80) NOT NULL,
          setting_value TEXT NOT NULL,
          PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prereg_seed(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM prereg_campaign')->fetchColumn();
    if ($count === 0) {
        $y = (int) date('Y');
        // Official calendar is May→Sep; keep a wide open window so the seeded campaign is usable year-round until staff tightens dates.
        $pdo->prepare(
            'INSERT INTO prereg_campaign (label, current_year_label, next_year_label, opens_at, closes_at, advance_amount, status, notes)
             VALUES (?, ?, ?, ?, ?, 200.00, ?, ?)'
        )->execute([
            'Préinscription annuelle ' . $y . '/' . ($y + 1),
            $y . '/' . ($y + 1),
            ($y + 1) . '/' . ($y + 2),
            sprintf('%04d-05-01', $y),
            sprintf('%04d-12-31', $y),
            'open',
            'PA-SAE-01 — engagement SA-01 + acompte 200 DT. N’équivaut pas à une réinscription finale.',
        ]);
    } else {
        // Heal early seed that closed in August while status stayed "open".
        $today = date('Y-m-d');
        $newClose = date('Y') . '-12-31';
        $pdo->prepare(
            "UPDATE prereg_campaign SET closes_at = ? WHERE status = 'open' AND closes_at IS NOT NULL AND closes_at < ?"
        )->execute([$newClose, $today]);
    }
    $set = $pdo->prepare('INSERT IGNORE INTO prereg_setting (setting_key, setting_value) VALUES (?, ?)');
    $set->execute(['advance_amount', '200']);
    $set->execute([
        'disclaimer_fr',
        'La préinscription est une démarche administrative anticipée. Elle ne constitue pas une réinscription finale et ne garantit pas le passage au niveau supérieur, qui dépend de la situation pédagogique, administrative et financière.',
    ]);
}

function prereg_open_campaigns(): array
{
    prereg_ensure();
    return db()->query(
        "SELECT * FROM prereg_campaign
         WHERE status = 'open'
           AND (opens_at IS NULL OR opens_at <= CURRENT_DATE)
           AND (closes_at IS NULL OR closes_at >= CURRENT_DATE)
         ORDER BY id_campaign DESC"
    )->fetchAll();
}

function prereg_find_campaign(int $id): ?array
{
    prereg_ensure();
    $stmt = db()->prepare('SELECT * FROM prereg_campaign WHERE id_campaign = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function prereg_student_commitment(int $studentId, int $campaignId): ?array
{
    prereg_ensure();
    $stmt = db()->prepare('SELECT * FROM prereg_commitment WHERE id_etudiant = ? AND id_campaign = ? LIMIT 1');
    $stmt->execute([$studentId, $campaignId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function prereg_docs_dir(): string
{
    $dir = storage_path('prereg-docs');
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Stockage préinscription inaccessible.');
    }
    return $dir;
}

/**
 * @param array<string, mixed> $data
 * @param array<string, mixed>|null $file
 */
function prereg_submit_sa01(int $studentId, array $profile, array $data, ?array $file = null): int
{
    prereg_ensure();
    $campaignId = (int) ($data['id_campaign'] ?? 0);
    $camp = prereg_find_campaign($campaignId);
    if (!$camp || (string) $camp['status'] !== 'open') {
        throw new InvalidArgumentException('Campagne de préinscription fermée ou introuvable.');
    }
    if (empty($data['accept_commitment'])) {
        throw new InvalidArgumentException('Vous devez accepter l’engagement SA-01.');
    }
    $intended = trim((string) ($data['intended_level'] ?? ''));
    if ($intended === '') {
        throw new InvalidArgumentException('Indiquez le niveau visé pour l’année prochaine.');
    }

    $existing = prereg_student_commitment($studentId, $campaignId);
    $receiptStored = $existing['receipt_stored'] ?? '';
    if ($file && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $receiptStored = prereg_store_receipt($file);
    }

    $payload = [
        'id_campaign' => $campaignId,
        'id_etudiant' => $studentId,
        'num_inscription' => (string) ($profile['num_inscription'] ?? ''),
        'nom' => (string) ($profile['nom'] ?? ''),
        'prenom' => (string) ($profile['prenom'] ?? ''),
        'current_level' => (string) ($profile['niveau'] ?? $data['current_level'] ?? ''),
        'current_group' => trim((string) ($data['current_group'] ?? $profile['groupe'] ?? '')),
        'intended_level' => $intended,
        'current_year_label' => (string) ($camp['current_year_label'] ?? ''),
        'commitment_text_accepted' => 1,
        'advance_amount' => (float) ($camp['advance_amount'] ?? 200),
        'receipt_stored' => (string) $receiptStored,
        'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 500),
        'status' => 'submitted',
    ];

    $pdo = db();
    if ($existing) {
        $pdo->prepare(
            'UPDATE prereg_commitment SET
               num_inscription=?, nom=?, prenom=?, current_level=?, current_group=?, intended_level=?,
               current_year_label=?, commitment_text_accepted=1, submitted_at=COALESCE(submitted_at, CURRENT_TIMESTAMP),
               advance_amount=?, receipt_stored=?, notes=?, status=?, updated_at=CURRENT_TIMESTAMP
             WHERE id_commitment=?'
        )->execute([
            $payload['num_inscription'],
            $payload['nom'],
            $payload['prenom'],
            $payload['current_level'],
            $payload['current_group'],
            $payload['intended_level'],
            $payload['current_year_label'],
            $payload['advance_amount'],
            $payload['receipt_stored'],
            $payload['notes'],
            $payload['status'],
            (int) $existing['id_commitment'],
        ]);
        return (int) $existing['id_commitment'];
    }

    $pdo->prepare(
        'INSERT INTO prereg_commitment
         (id_campaign, id_etudiant, num_inscription, nom, prenom, current_level, current_group, intended_level,
          current_year_label, commitment_text_accepted, submitted_at, advance_amount, receipt_stored, notes, status)
         VALUES (?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP,?,?,?,?)'
    )->execute([
        $payload['id_campaign'],
        $payload['id_etudiant'],
        $payload['num_inscription'],
        $payload['nom'],
        $payload['prenom'],
        $payload['current_level'],
        $payload['current_group'],
        $payload['intended_level'],
        $payload['current_year_label'],
        $payload['advance_amount'],
        $payload['receipt_stored'],
        $payload['notes'],
        $payload['status'],
    ]);
    return (int) $pdo->lastInsertId();
}

/** @param array<string, mixed> $file */
function prereg_store_receipt(array $file): string
{
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp) || $size < 1 || $size > 4 * 1024 * 1024) {
        throw new InvalidArgumentException('Quittance invalide (max 4 Mo).');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $map = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $ext = $map[$mime] ?? '';
    if ($ext === '') {
        throw new InvalidArgumentException('Format de quittance non autorisé (PDF/JPG/PNG).');
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $target = prereg_docs_dir() . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Enregistrement de la quittance impossible.');
    }
    @chmod($target, 0640);
    return $stored;
}

function prereg_verify_advance(int $commitmentId, array $data, string $actor): void
{
    prereg_ensure();
    $paid = !empty($data['advance_paid']) ? 1 : 0;
    db()->prepare(
        'UPDATE prereg_commitment SET
           advance_paid = ?,
           advance_paid_at = ?,
           advance_method = ?,
           advance_receipt_no = ?,
           staff_notes = ?,
           verified_by_finance = ?,
           verified_at = CURRENT_TIMESTAMP,
           status = ?,
           updated_at = CURRENT_TIMESTAMP
         WHERE id_commitment = ?'
    )->execute([
        $paid,
        $paid ? (string) ($data['advance_paid_at'] ?? date('Y-m-d')) : null,
        mb_substr(trim((string) ($data['advance_method'] ?? '')), 0, 40),
        mb_substr(trim((string) ($data['advance_receipt_no'] ?? '')), 0, 80),
        mb_substr(trim((string) ($data['staff_notes'] ?? '')), 0, 500),
        mb_substr($actor, 0, 120),
        $paid ? 'advance_paid' : 'submitted',
        $commitmentId,
    ]);
}

function prereg_mark_affairs(int $commitmentId, string $actor, string $notes = ''): void
{
    prereg_ensure();
    db()->prepare(
        'UPDATE prereg_commitment SET verified_by_affairs = ?, staff_notes = CASE WHEN ? = "" THEN staff_notes ELSE ? END, updated_at = CURRENT_TIMESTAMP
         WHERE id_commitment = ?'
    )->execute([mb_substr($actor, 0, 120), $notes, mb_substr($notes, 0, 500), $commitmentId]);
}

/** @return list<array<string, mixed>> */
function prereg_sa02_rows(int $campaignId, string $level = ''): array
{
    prereg_ensure();
    $sql = 'SELECT * FROM prereg_commitment WHERE id_campaign = ?';
    $params = [$campaignId];
    if ($level !== '') {
        $sql .= ' AND current_level = ?';
        $params[] = $level;
    }
    $sql .= ' ORDER BY submitted_at, nom, prenom';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** @return array<string, mixed> */
function prereg_sa03_summary(int $campaignId): array
{
    prereg_ensure();
    $camp = prereg_find_campaign($campaignId);
    $advance = (float) ($camp['advance_amount'] ?? 200);
    $rows = prereg_sa02_rows($campaignId);
    $byLevel = [];
    $paid = 0;
    $unpaid = 0;
    foreach ($rows as $row) {
        $level = (string) ($row['current_level'] ?: '—');
        if (!isset($byLevel[$level])) {
            $byLevel[$level] = ['level' => $level, 'commitments' => 0, 'paid' => 0, 'unpaid' => 0];
        }
        $byLevel[$level]['commitments']++;
        if ((int) $row['advance_paid'] === 1) {
            $byLevel[$level]['paid']++;
            $paid++;
        } else {
            $byLevel[$level]['unpaid']++;
            $unpaid++;
        }
    }
    return [
        'campaign' => $camp,
        'levels' => array_values($byLevel),
        'total_commitments' => count($rows),
        'total_paid' => $paid,
        'total_unpaid' => $unpaid,
        'advance_unit' => $advance,
        'amount_collected' => $paid * $advance,
        'amount_outstanding' => $unpaid * $advance,
    ];
}
