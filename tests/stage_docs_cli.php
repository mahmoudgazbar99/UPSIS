<?php
declare(strict_types=1);

$storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'upsis-stage-docs-' . bin2hex(random_bytes(4));
putenv('STAGE_DOCS_DIR=' . $storage);
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

$pdo = db();
$students = $pdo->query('SELECT id_etudiant FROM etudiant WHERE statut = "actif" ORDER BY id_etudiant LIMIT 2')->fetchAll();
if (count($students) < 2) {
    echo "FAIL need two active students\n";
    exit(1);
}
$studentA = (int) $students[0]['id_etudiant'];
$studentB = (int) $students[1]['id_etudiant'];
$marker = 'CLI docs ' . bin2hex(random_bytes(3));
$orgId = 0;
$campaignId = 0;
$placementA = 0;
$placementB = 0;
$officerId = 0;
$supervisorId = 0;
$otherSupervisor = 0;
$typeSecret = 0;
$typeReport = 0;
$requirement = 0;
$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

$cin = static function () use ($pdo): string {
    do {
        $value = (string) random_int(10000000, 99999999);
        $taken = $pdo->prepare('SELECT 1 FROM stage_user WHERE cin = ?');
        $taken->execute([$value]);
    } while ($taken->fetchColumn());
    return $value;
};

try {
    $pdo->prepare('INSERT INTO stage_organisme (nom, capacite, active) VALUES (?, 5, 1)')->execute([$marker]);
    $orgId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO stage_campagne (annee_label, periode_code, date_debut, date_fin, statut) VALUES ("2098-2099", "S1", "2098-03-01", "2098-03-31", "publiee")')->execute();
    $campaignId = (int) $pdo->lastInsertId();
    $insertPlacement = $pdo->prepare(
        'INSERT INTO stage_placement (id_etudiant, id_organisme, id_campagne, annee_label, periode_code, date_debut, date_fin, statut, id_stage_superviseur)
         VALUES (?, ?, ?, "2098-2099", "S1", "2098-03-01", "2098-03-31", "affecte", ?)'
    );
    $hash = password_hash('StageDoc9', PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO stage_user (cin, nom, password, responsabilite, active) VALUES (?, "CLI docs", ?, "officer", 1)')->execute([$cin(), $hash]);
    $officerId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO stage_user (cin, nom, password, responsabilite, active) VALUES (?, "CLI encadrant", ?, "superviseur", 1)')->execute([$cin(), $hash]);
    $supervisorId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO stage_user (cin, nom, password, responsabilite, active) VALUES (?, "CLI autre", ?, "superviseur", 1)')->execute([$cin(), $hash]);
    $otherSupervisor = (int) $pdo->lastInsertId();
    $insertPlacement->execute([$studentA, $orgId, $campaignId, $supervisorId]);
    $placementA = (int) $pdo->lastInsertId();
    $insertPlacement->execute([$studentB, $orgId, $campaignId, $otherSupervisor]);
    $placementB = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO stage_doc_type (code, libelle, deposant, validation_requise, visible_encadrant) VALUES (?, "CLI secret", "personnel", 1, 0)')->execute(['cli_secret_' . bin2hex(random_bytes(2))]);
    $typeSecret = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO stage_doc_type (code, libelle, deposant, validation_requise, visible_encadrant) VALUES (?, "CLI rapport", "les_deux", 1, 1)')->execute(['cli_rapport_' . bin2hex(random_bytes(2))]);
    $typeReport = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO stage_doc_exigence (id_type, id_campagne, obligatoire, deposant, validation_requise, visible_encadrant) VALUES (?, ?, 1, "les_deux", 1, 1)')->execute([$typeReport, $campaignId]);
    $requirement = (int) $pdo->lastInsertId();

    $_SESSION['user_role'] = 'stage';
    $_SESSION['stage_duty'] = 'officer';
    $_SESSION['stage_id'] = $officerId;
    $check(role_can('stage.documents.review') && !role_can('stage.evaluate'), 'manager reviews documents without taking the supervisor evaluation');

    $state = stage_doc_dossier_state(stage_doc_placement($placementA));
    $check($state['missing'] === 1 && !$state['complete'], 'required document is missing before upload');

    $first = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($first, $pdf);
    $version = stage_doc_import($placementA, $typeReport, $first, 'rapport.pdf');
    $row = stage_doc_version_row($version);
    $check($row !== null && (int) $row['id_etudiant'] === $studentA && (int) $row['id_placement'] === $placementA, 'file is linked to the student and the placement');
    $path = stage_doc_absolute((string) $row['cle_stockage']);
    $check(is_file($path) && hash_file('sha256', $path) === (string) $row['empreinte'], 'file is stored outside the site and its hash matches');
    $check(!str_contains(strtolower(str_replace('\\', '/', $path)), '/htdocs/webapp/'), 'storage path is not inside the public webapp');

    file_put_contents($first, $pdf . "\n%version2\n");
    $second = stage_doc_import($placementA, $typeReport, $first, 'rapport-corrige.pdf');
    $versions = stage_doc_versions((int) $row['id_piece']);
    $check(count($versions) === 2 && (int) $versions[0]['id_version'] === $second, 'replacement keeps the previous version');
    $check(is_file($path), 'the first stored file is still on disk');

    $secretFile = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($secretFile, $pdf);
    $secretVersion = stage_doc_import($placementA, $typeSecret, $secretFile, 'convention.pdf');
    $secret = stage_doc_version_row($secretVersion);

    $_SESSION['user_role'] = 'etudiant';
    $_SESSION['id_etudiant'] = $studentA;
    $check(stage_doc_may_open(stage_doc_placement($placementA)) && !stage_doc_may_open(stage_doc_placement($placementB)), 'student opens only their own dossier');
    $check(stage_doc_can_read_version($row), 'student can read the report stored on their placement');
    $own = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($own, $pdf);
    $studentVersion = 0;
    try {
        $studentVersion = stage_doc_import($placementB, $typeReport, $own, 'intrus.pdf');
        $check(false, 'student cannot upload into another dossier');
    } catch (InvalidArgumentException $exception) {
        $check(true, 'student cannot upload into another dossier');
    }
    $studentVersion = stage_doc_import($placementA, $typeReport, $own, 'mon-rapport.pdf');
    $check($studentVersion > $second, 'student can upload to their own dossier');
    try {
        stage_doc_import($placementA, $typeSecret, $own, 'secret.pdf');
        $check(false, 'student cannot upload a staff-only category');
    } catch (InvalidArgumentException $exception) {
        $check(true, 'student cannot upload a staff-only category');
    }

    $_SESSION['user_role'] = 'stage';
    $_SESSION['stage_duty'] = 'superviseur';
    $_SESSION['stage_id'] = $supervisorId;
    $check(stage_doc_can_read_version(stage_doc_version_row($studentVersion)), 'supervisor reads the report of an assigned student');
    $check(!stage_doc_can_read_version($secret), 'supervisor cannot read a confidential administrative file');
    $check(!stage_doc_may_open(stage_doc_placement($placementB)), 'supervisor cannot open another supervisor student');
    $check(!role_can('stage.documents.review') && !role_can('stage.documents.upload'), 'supervisor cannot validate or upload administrative files');

    $_SESSION['stage_id'] = $otherSupervisor;
    $check(!stage_doc_can_read_version(stage_doc_version_row($studentVersion)), 'another supervisor cannot read the file by changing nothing but their identity');

    $_SESSION['user_role'] = 'etudiant';
    $_SESSION['id_etudiant'] = $studentB;
    $check(!stage_doc_can_read_version(stage_doc_version_row($studentVersion)), 'another student cannot read the file');

    $_SESSION['user_role'] = 'stage';
    $_SESSION['stage_duty'] = 'officer';
    $_SESSION['stage_id'] = $officerId;
    $bad = tempnam(sys_get_temp_dir(), 'bad');
    file_put_contents($bad, "<?php echo 1;\n");
    try {
        stage_doc_import($placementA, $typeReport, $bad, 'note.php.pdf');
        $check(false, 'script content is rejected');
    } catch (InvalidArgumentException $exception) {
        $check(true, 'script content is rejected');
    }
    $huge = tempnam(sys_get_temp_dir(), 'big');
    file_put_contents($huge, $pdf . str_repeat('A', 200000));
    institution_setting_put('stage_doc_max_bytes', '102400');
    try {
        stage_doc_import($placementA, $typeReport, $huge, 'gros.pdf');
        $check(false, 'oversized file is rejected');
    } catch (InvalidArgumentException $exception) {
        $check(str_contains($exception->getMessage(), 'taille'), 'oversized file is rejected');
    }
    institution_setting_put('stage_doc_max_bytes', '8388608');

    $pieceId = (int) stage_doc_version_row($studentVersion)['id_piece'];
    stage_doc_review($pieceId, 'valide');
    $validated = stage_doc_piece_row($pieceId);
    $check($validated !== null && (string) $validated['statut'] === 'valide', 'manager validates the current version');
    $after = stage_doc_dossier_state(stage_doc_placement($placementA));
    $check($after['complete'] && $after['missing'] === 0, 'completeness comes from the stored requirement');

    $_SESSION['user_role'] = 'etudiant';
    $_SESSION['id_etudiant'] = $studentA;
    $pdo->prepare('UPDATE stage_placement SET statut = "termine" WHERE id_placement = ?')->execute([$placementA]);
    try {
        stage_doc_import($placementA, $typeReport, $own, 'apres-cloture.pdf');
        $check(false, 'closed placement rejects a new student upload');
    } catch (InvalidArgumentException $exception) {
        $check(true, 'closed placement rejects a new student upload');
    }
    $still = stage_doc_version_row($version);
    $check(is_file(stage_doc_absolute((string) $still['cle_stockage'])), 'closing the placement keeps the archived file');

    $traversed = false;
    try {
        stage_doc_absolute('../' . str_repeat('a', 61));
    } catch (InvalidArgumentException $exception) {
        $traversed = true;
    }
    $check($traversed, 'storage keys cannot traverse directories');

    $_SESSION['user_role'] = 'stage';
    $_SESSION['stage_duty'] = 'officer';
    $_SESSION['stage_id'] = $officerId;
    $check(!stage_doc_can_read_version(['id_placement' => $placementB, 'visible_encadrant' => 0]) || stage_doc_may_open(stage_doc_placement($placementB)), 'manager can still open the placement for administration');
} catch (Throwable $exception) {
    echo 'FAIL ' . $exception->getMessage() . PHP_EOL;
    $failures[] = 'exception';
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ([$placementA, $placementB] as $placementId) {
        if ($placementId < 1) {
            continue;
        }
        $ids = $pdo->prepare('SELECT id_piece FROM stage_doc_piece WHERE id_placement = ?');
        $ids->execute([$placementId]);
        foreach ($ids->fetchAll() as $piece) {
            $pdo->prepare('DELETE FROM stage_doc_version WHERE id_piece = ?')->execute([(int) $piece['id_piece']]);
        }
        $pdo->prepare('DELETE FROM stage_doc_piece WHERE id_placement = ?')->execute([$placementId]);
        $pdo->prepare('DELETE FROM stage_placement WHERE id_placement = ?')->execute([$placementId]);
    }
    if ($requirement > 0) {
        $pdo->prepare('DELETE FROM stage_doc_exigence WHERE id_exigence = ?')->execute([$requirement]);
    }
    foreach ([$typeSecret, $typeReport] as $typeId) {
        if ($typeId > 0) {
            $pdo->prepare('DELETE FROM stage_doc_type WHERE id_type = ?')->execute([$typeId]);
        }
    }
    if ($campaignId > 0) {
        $pdo->prepare('DELETE FROM stage_campagne WHERE id_campagne = ?')->execute([$campaignId]);
    }
    if ($orgId > 0) {
        $pdo->prepare('DELETE FROM stage_organisme WHERE id_organisme = ?')->execute([$orgId]);
    }
    foreach ([$officerId, $supervisorId, $otherSupervisor] as $userId) {
        if ($userId > 0) {
            $pdo->prepare('DELETE FROM stage_user WHERE id_stage = ?')->execute([$userId]);
        }
    }
    if (is_dir($storage)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($storage);
    }
}

echo $failures === [] ? "ALL PASSED\n" : ("FAILED " . count($failures) . "\n");
exit($failures === [] ? 0 : 1);
