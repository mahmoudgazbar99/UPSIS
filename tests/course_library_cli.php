<?php
declare(strict_types=1);

$storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'upsis-cours-' . bin2hex(random_bytes(4));
putenv('COURSE_LIBRARY_DIR=' . $storage);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/application/bootstrap.php';
require_once app_path('support/course_library.php');

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
    echo ($ok ? 'ok   ' : 'fail ') . $label . PHP_EOL;
};
$throws = static function (callable $work): bool {
    try {
        $work();
        return false;
    } catch (InvalidArgumentException $exception) {
        return true;
    }
};
$errorOf = static function (callable $work): string {
    try {
        $work();
        return '';
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage();
    }
};

$pdo = db();
course_library_ensure($pdo);
audit_ensure_table($pdo);

$tag = bin2hex(random_bytes(3));
$marker = 'CLI cours ' . $tag;
$semester = 'Z' . strtoupper($tag);
$auditBaseline = (int) $pdo->query('SELECT COALESCE(MAX(id_audit), 0) FROM audit_log')->fetchColumn();
$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

$teacherA = 0;
$teacherB = 0;
$subject = 0;
$otherSubject = 0;
$groupCours = 0;
$groupTd = 0;
$groupOther = 0;
$students = [];
$tempFiles = [];

$tempPdf = static function (string $content) use (&$tempFiles): string {
    $path = tempnam(sys_get_temp_dir(), 'crs');
    file_put_contents($path, $content);
    $tempFiles[] = $path;
    return $path;
};
$notifications = static function (int $studentId) use ($pdo): array {
    $query = $pdo->prepare('SELECT sujet, message_body, lien FROM etudiant_notifications WHERE id_etudiant = ? ORDER BY id_notif');
    $query->execute([$studentId]);
    return $query->fetchAll();
};
$treeHasFile = static function (array $nodes, int $fileId) use (&$treeHasFile): bool {
    foreach ($nodes as $node) {
        foreach ($node['files'] as $file) {
            if ($file['id'] === $fileId) {
                return true;
            }
        }
        if ($treeHasFile($node['folders'], $fileId)) {
            return true;
        }
    }
    return false;
};

try {
    $hash = password_hash('CoursCli9', PASSWORD_DEFAULT);
    $insertTeacher = $pdo->prepare(
        'INSERT INTO enseignant (cin, nom, prenom, type_enseignant, num_tel, email, adresse, password, active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $teacherCin = static function () use ($pdo): string {
        do {
            $value = (string) random_int(10000000, 99999999);
            $taken = $pdo->prepare('SELECT 1 FROM enseignant WHERE cin = ?');
            $taken->execute([$value]);
        } while ($taken->fetchColumn());
        return $value;
    };
    $insertTeacher->execute([$teacherCin(), 'Alpha' . $tag, 'Prof', 'Cours', '00000000', 'cli-a-' . $tag . '@example.invalid', 'CLI', $hash]);
    $teacherA = (int) $pdo->lastInsertId();
    $insertTeacher->execute([$teacherCin(), 'Beta' . $tag, 'Prof', 'Cours', '00000000', 'cli-b-' . $tag . '@example.invalid', 'CLI', $hash]);
    $teacherB = (int) $pdo->lastInsertId();

    $insertSubject = $pdo->prepare(
        'INSERT INTO matiere (code_ue, description_ue, code_ecue, description_ecue, semestre, regime)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insertSubject->execute(['UECLI' . $tag, 'UE CLI', 'CLI' . $tag, 'Anatomie CLI', $semester, 'Mixte']);
    $subject = (int) $pdo->lastInsertId();
    $insertSubject->execute(['UECLJ' . $tag, 'UE CLI', 'CLJ' . $tag, 'Physiologie CLI', $semester, 'Mixte']);
    $otherSubject = (int) $pdo->lastInsertId();

    $insertGroup = $pdo->prepare(
        'INSERT INTO groupe (semestre, type_groupe, id_groupe_parent, numero, capacite) VALUES (?, ?, ?, ?, 40)'
    );
    $insertGroup->execute([$semester, 'Cours', null, 1]);
    $groupCours = (int) $pdo->lastInsertId();
    $insertGroup->execute([$semester, 'TD', $groupCours, 1]);
    $groupTd = (int) $pdo->lastInsertId();
    $insertGroup->execute([$semester, 'Cours', null, 2]);
    $groupOther = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO enseignant_affectation (id_enseignant, id_mat, id_groupe) VALUES (?, ?, ?)')
        ->execute([$teacherA, $subject, $groupCours]);
    $pdo->prepare('INSERT INTO enseignant_affectation (id_enseignant, id_mat, id_groupe) VALUES (?, ?, ?)')
        ->execute([$teacherB, $otherSubject, $groupOther]);

    $specId = (int) $pdo->query('SELECT id_spec FROM specialite ORDER BY id_spec LIMIT 1')->fetchColumn();
    if ($specId <= 0) {
        throw new RuntimeException('Aucune spécialité disponible pour les fixtures.');
    }
    $insertStudent = $pdo->prepare(
        'INSERT INTO etudiant (nom, prenom, nom_ar, prenom_ar, date_naissance, lieu_naissance, lieu_naissance_ar, sexe,
                               nationalite, etat_civil, cin_passeport, num_tel, adresse, email, num_inscription,
                               date_inscrit, id_spec, niveau, statut, annee_bac, specialite_bac)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertAccount = $pdo->prepare('INSERT INTO etudiant_compte (id_etudiant, password, active) VALUES (?, ?, ?)');
    $joinGroup = $pdo->prepare('INSERT INTO groupe_etudiant (id_groupe, id_etudiant) VALUES (?, ?)');
    foreach ([
        'cours' => [$groupCours, 1],
        'td' => [$groupTd, 1],
        'other' => [$groupOther, 1],
        'inactive' => [$groupCours, 0],
    ] as $key => [$groupId, $active]) {
        $unique = 'CLI' . $tag . $key;
        $insertStudent->execute([
            'Etudiant' . $tag, ucfirst($key), 'x', 'x', '2000-01-01', 'Gabès', 'x', 'M',
            'Tunisienne', 'Célibataire', $unique, '00000000', 'CLI', strtolower($unique) . '@example.invalid', $unique,
            '2026-09-01', $specId, '1', 'actif', 2019, 'Sciences',
        ]);
        $students[$key] = (int) $pdo->lastInsertId();
        $insertAccount->execute([$students[$key], $hash, $active]);
        $joinGroup->execute([$groupId, $students[$key]]);
    }

    $_SESSION['user_role'] = 'enseignant';
    $_SESSION['teacher_id'] = $teacherA;

    $subjects = course_teacher_subjects($teacherA);
    $check(count($subjects) === 1 && $subjects[0]['id_mat'] === $subject && $subjects[0]['semestre'] === $semester, 'teacher subjects list only the assigned subject');
    $check(course_teacher_owns_subject($teacherA, $subject) && !course_teacher_owns_subject($teacherA, $otherSubject), 'subject ownership follows enseignant_affectation');

    $courseId = course_create_root($teacherA, $subject, '  ' . $marker . '   Anatomie ');
    $root = course_folder_for_teacher($teacherA, $courseId);
    $check($root !== null && $root['id_cours'] === $courseId && $root['id_parent'] === null && $root['nom'] === $marker . ' Anatomie' && $root['semestre'] === $semester, 'teacher A creates a course for the assigned subject');
    $check($throws(static fn () => course_create_root($teacherA, $otherSubject, $marker . ' Interdit')), 'teacher A cannot create a course for an unassigned subject');
    $check($throws(static fn () => course_create_root(0, $subject, $marker . ' Zero')), 'teacher id 0 is rejected');
    $check($throws(static fn () => course_create_root($teacherA, $subject, mb_strtoupper($marker . ' anatomie'))), 'duplicate course name is rejected');

    $chapter = course_create_folder($teacherA, $courseId, $marker . ' Chapitre 1');
    $chapterRow = course_folder_for_teacher($teacherA, $chapter);
    $check($chapterRow !== null && $chapterRow['id_cours'] === $courseId && $chapterRow['id_mat'] === $subject && $chapterRow['id_enseignant'] === $teacherA, 'subfolder inherits course, subject and teacher');
    $check($throws(static fn () => course_create_folder($teacherA, $courseId, $marker . ' chapitre 1')), 'duplicate sibling folder is rejected');

    $fileId = course_store_pdf_file($teacherA, $chapter, $tempPdf($pdf), 'C:\\fakepath\\..\\Cours 1.PDF');
    $meta = course_file_meta($fileId);
    $check($meta !== null && $meta['nom'] === 'Cours 1.pdf' && $meta['id_cours'] === $courseId && $meta['id_enseignant'] === $teacherA, 'stored PDF keeps a sanitized display name');
    $check($meta !== null && is_file($meta['path']) && preg_match('/^[a-f0-9]{32}\.pdf$/', basename($meta['path'])) === 1, 'PDF is stored on disk under a random name');
    $check($throws(static fn () => course_store_pdf_file($teacherA, $chapter, $tempPdf("<?php echo 1;\n"), 'script.pdf')), 'non-PDF content is rejected');
    $check($throws(static fn () => course_store_pdf_file($teacherA, $chapter, $tempPdf(''), 'vide.pdf')), 'empty file is rejected');
    $check($throws(static fn () => course_upload_files($teacherA, $chapter, ['name' => ['x.pdf'], 'tmp_name' => [$tempPdf($pdf)], 'error' => [UPLOAD_ERR_OK], 'size' => [10]])), 'upload refuses files that were not uploaded over HTTP');
    $bulk = static fn (int $n): array => [
        'name' => array_fill(0, $n, 'gros.pdf'),
        'tmp_name' => array_fill(0, $n, '/tmp/absent'),
        'error' => array_fill(0, $n, UPLOAD_ERR_OK),
        'size' => array_fill(0, $n, COURSE_MAX_FILE_BYTES),
    ];
    $check(str_contains($errorOf(static fn () => course_upload_files($teacherA, $chapter, $bulk(5))), '120 Mo'), 'upload over 120 Mo in total is rejected server-side');
    $underLimit = $errorOf(static fn () => course_upload_files($teacherA, $chapter, $bulk(4)));
    $check($underLimit !== '' && !str_contains($underLimit, '120 Mo'), 'upload of 100 Mo passes the total-size guard');
    $check($throws(static fn () => course_upload_files($teacherA, $chapter, $bulk(COURSE_MAX_FILES_PER_UPLOAD + 1))), 'more than 20 files per upload is rejected');

    $spoofId = course_store_pdf_file($teacherA, $chapter, $tempPdf($pdf), "Facture\u{202E}fdp.exe\u{2066}\t2.pdf");
    $check(course_file_meta($spoofId)['nom'] === 'Facturefdp.exe 2.pdf', 'bidi override and control characters are stripped from uploaded names');
    course_delete_file($teacherA, $spoofId);
    $check(course_upload_display_name("\u{202E}\u{202D}.pdf") === 'document.pdf', 'name made only of bidi characters falls back to document.pdf');
    $check($throws(static fn () => course_validate_name("a\u{2028}b")) && $throws(static fn () => course_validate_name("a\u{061C}b")), 'typed names with line separator or Arabic letter mark are rejected');

    $_SESSION['teacher_id'] = $teacherB;
    $check(course_folder_for_teacher($teacherB, $courseId) === null, 'teacher B cannot load teacher A folder');
    $check($throws(static fn () => course_rename_folder($teacherB, $chapter, $marker . ' Pirate')), 'teacher B cannot rename teacher A folder');
    $check($throws(static fn () => course_delete_folder($teacherB, $courseId)), 'teacher B cannot delete teacher A course');
    $check($throws(static fn () => course_create_folder($teacherB, $courseId, $marker . ' Pirate')), 'teacher B cannot create a folder in teacher A course');
    $check($throws(static fn () => course_store_pdf_file($teacherB, $chapter, $tempPdf($pdf), 'pirate.pdf')), 'teacher B cannot upload into teacher A folder');
    $check($throws(static fn () => course_upload_files($teacherB, $chapter, ['name' => ['x.pdf'], 'tmp_name' => ['/tmp/x'], 'error' => [UPLOAD_ERR_OK], 'size' => [10]])), 'teacher B upload call is rejected before reading files');
    $check($throws(static fn () => course_rename_file($teacherB, $fileId, 'pirate')), 'teacher B cannot rename teacher A file');
    $check($throws(static fn () => course_delete_file($teacherB, $fileId)), 'teacher B cannot delete teacher A file');
    $check(course_tree_for_teacher($teacherB) === [], 'teacher B tree does not contain teacher A course');
    $_SESSION['teacher_id'] = $teacherA;
    $check(course_folder_for_teacher($teacherA, $chapter) !== null && course_file_meta($fileId) !== null, 'teacher A data is intact after teacher B attempts');

    $link = 'dashboard.php?panel=cours&cours=' . $courseId . '&dossier=' . $chapter;
    $coursNotes = $notifications($students['cours']);
    $tdNotes = $notifications($students['td']);
    $check(count($coursNotes) === 1 && $coursNotes[0]['lien'] === $link && str_starts_with((string) $coursNotes[0]['sujet'], 'Nouveau cours : '), 'student of the assigned Cours group is notified with the link');
    $check(count($tdNotes) === 1 && $tdNotes[0]['lien'] === $link, 'student of the TD child group is notified');
    $check($notifications($students['other']) === [] && $notifications($students['inactive']) === [], 'other-group and inactive students are not notified');
    $latest = student_notifications_latest($pdo, $students['cours']);
    $check(($latest[0]['link'] ?? null) === $link, 'notification payload exposes the link');
    $payload = student_notifications_payload($pdo, $students['cours']);
    $check(($payload['messages'][0]['link'] ?? null) === $link, 'notification payload messages include link');

    student_notify($pdo, $students['cours'], 'Test ' . $tag, 'Lien externe', 'https://evil.example/');
    student_notify($pdo, $students['cours'], 'Test ' . $tag, 'Lien injecté', 'dashboard.php?panel=cours&cours=1&x=<script>');
    student_notify($pdo, $students['cours'], 'Test ' . $tag, 'Sans lien');
    $rows = $notifications($students['cours']);
    $check(count($rows) === 4 && $rows[1]['lien'] === null && $rows[2]['lien'] === null && $rows[3]['lien'] === null, 'only dashboard course links are stored');
    $latest = student_notifications_latest($pdo, $students['cours']);
    $check($latest[0]['link'] === '', 'missing link is exposed as an empty string');
    $check(
        student_notification_link('dashboard.php?panel=cours') !== null
        && student_notification_link('dashboard.php?panel=cours&cours=3&dossier=4') !== null
        && student_notification_link('dashboard.php?panel=cours&x=1') === null
        && student_notification_link('dashboard.php?panel=cours&dossier=4') === null
        && student_notification_link('dashboard.php?panel=cours&cours=1&cours=2') === null
        && student_notification_link('dashboard.php?panel=cours&panel=notes') === null
        && student_notification_link('/dashboard.php?panel=cours') === null
        && student_notification_link('//evil.example/dashboard.php?panel=cours') === null,
        'notification links accept only panel=cours[&cours=N[&dossier=N]]'
    );

    $linkCount = static function (int $studentId, string $lien) use ($pdo): int {
        $query = $pdo->prepare('SELECT COUNT(*) FROM etudiant_notifications WHERE id_etudiant = ? AND lien = ?');
        $query->execute([$studentId, $lien]);
        return (int) $query->fetchColumn();
    };
    $repeatA = course_store_pdf_file($teacherA, $chapter, $tempPdf($pdf), 'Zz relance A.pdf');
    $check($linkCount($students['cours'], $link) === 1 && $linkCount($students['td'], $link) === 1, 'repeated upload into the same folder does not duplicate an unread notification');
    $pdo->prepare('UPDATE etudiant_notifications SET is_read = 1 WHERE id_etudiant = ? AND lien = ?')->execute([$students['cours'], $link]);
    $repeatB = course_store_pdf_file($teacherA, $chapter, $tempPdf($pdf), 'Zz relance B.pdf');
    $check($linkCount($students['cours'], $link) === 2 && $linkCount($students['td'], $link) === 1, 'a new notification is sent once the previous one was read');
    $check($notifications($students['other']) === [] && $notifications($students['inactive']) === [], 'repeated uploads still skip ineligible students');
    course_delete_file($teacherA, $repeatA);
    course_delete_file($teacherA, $repeatB);

    $audience = course_student_ids_for_course($courseId);
    sort($audience);
    $expected = [$students['cours'], $students['td']];
    sort($expected);
    $check($audience === $expected, 'course audience excludes other groups and inactive accounts');

    $treeCours = course_tree_for_student($students['cours']);
    $treeTd = course_tree_for_student($students['td']);
    $check(count($treeCours) === 1 && $treeCours[0]['id'] === $courseId && $treeHasFile($treeCours, $fileId), 'student in the assigned Cours group sees the course and file');
    $check(count($treeTd) === 1 && $treeTd[0]['id'] === $courseId && $treeHasFile($treeTd, $fileId), 'student in the TD child group sees the course');
    $check(course_tree_for_student($students['other']) === [], 'student in another group does not see the course');
    $check(course_tree_for_student($students['inactive']) === [], 'inactive student account does not see the course');
    $check(course_student_may_access_file($students['cours'], $fileId) && course_student_may_access_file($students['td'], $fileId), 'allowed students may open the file');
    $check(!course_student_may_access_file($students['other'], $fileId), 'student in another group may not open the file');
    $check(!course_student_may_access_file($students['inactive'], $fileId), 'inactive student may not open the file');
    $check(course_student_may_access_folder($students['td'], $chapter) && !course_student_may_access_folder($students['other'], $chapter), 'folder visibility follows the course');
    $check(!course_student_may_access_file(0, $fileId) && !course_student_may_access_file($students['cours'], 0), 'invalid ids are denied');

    $teacherTree = course_tree_for_teacher($teacherA);
    $course = $teacherTree[0] ?? [];
    $folder = $course['folders'][0] ?? [];
    $file = $folder['files'][0] ?? [];
    $check(
        count($teacherTree) === 1
        && array_keys($course) === ['id', 'nom', 'id_mat', 'matiere', 'semestre', 'enseignant', 'updated_at', 'folders', 'files']
        && $course['matiere'] === 'CLI' . $tag . ' — Anatomie CLI'
        && $course['enseignant'] === 'Prof Alpha' . $tag
        && preg_match('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/', $course['updated_at']) === 1
        && array_keys($folder) === ['id', 'nom', 'updated_at', 'folders', 'files']
        && array_keys($file) === ['id', 'nom', 'size', 'size_label', 'uploaded_at']
        && $file['id'] === $fileId && $file['size'] === strlen($pdf) && $file['size_label'] === strlen($pdf) . ' o',
        'teacher tree has the documented shape'
    );
    $check(course_size_label(1258291) === '1,2 Mo' && course_size_label(2048) === '2 Ko', 'size labels use French formatting');

    $parent = $chapter;
    for ($level = 3; $level <= 6; $level++) {
        $parent = course_create_folder($teacherA, $parent, $marker . ' Niveau ' . $level);
    }
    $check($throws(static fn () => course_create_folder($teacherA, $parent, $marker . ' Niveau 7')), 'seventh folder level is rejected');

    $limitRoot = course_create_root($teacherA, $subject, $marker . ' Limite');
    $rows = [];
    $params = [];
    for ($i = 1; $i < COURSE_MAX_FOLDERS_PER_COURSE; $i++) {
        $rows[] = '(?, ?, ?, ?, ?, ?)';
        array_push($params, $limitRoot, $limitRoot, $teacherA, $subject, $semester, 'Lot ' . $i);
    }
    $pdo->prepare('INSERT INTO cours_dossier (id_cours, id_parent, id_enseignant, id_mat, semestre, nom) VALUES ' . implode(', ', $rows))->execute($params);
    $check(str_contains($errorOf(static fn () => course_create_folder($teacherA, $limitRoot, $marker . ' En trop')), 'Nombre maximal de dossiers'), 'folder count per course is capped');
    course_delete_folder($teacherA, $limitRoot);
    $count = $pdo->prepare('SELECT COUNT(*) FROM cours_dossier WHERE id_cours = ?');
    $count->execute([$limitRoot]);
    $check((int) $count->fetchColumn() === 0, 'deleting a course with many folders removes every row');

    $rows = [];
    $params = [];
    for ($i = 1; $i <= COURSE_MAX_COURSES_PER_TEACHER; $i++) {
        $rows[] = '(NULL, NULL, ?, ?, ?, ?)';
        array_push($params, $teacherB, $otherSubject, $semester, $marker . ' Lot ' . $i);
    }
    $pdo->prepare('INSERT INTO cours_dossier (id_cours, id_parent, id_enseignant, id_mat, semestre, nom) VALUES ' . implode(', ', $rows))->execute($params);
    $check(str_contains($errorOf(static fn () => course_create_root($teacherB, $otherSubject, $marker . ' En trop')), 'Nombre maximal de cours'), 'course count per teacher is capped');
    $pdo->prepare('DELETE FROM cours_dossier WHERE id_enseignant = ? AND id_parent IS NULL')->execute([$teacherB]);

    course_rename_file($teacherA, $fileId, '  Résumé   final ');
    $check(course_file_meta($fileId)['nom'] === 'Résumé final.pdf', 'renamed file keeps the .pdf suffix');
    course_rename_file($teacherA, $fileId, 'Résumé final.PDF');
    $check(course_file_meta($fileId)['nom'] === 'Résumé final.pdf', 'suffix is not duplicated');
    $check($throws(static fn () => course_rename_file($teacherA, $fileId, '../x')), 'file rename rejects path separators');
    course_rename_folder($teacherA, $chapter, $marker . ' Chapitre un');
    $check(course_folder_for_teacher($teacherA, $chapter)['nom'] === $marker . ' Chapitre un', 'teacher A renames own folder');

    $check($throws(static fn () => course_validate_name('../x')), 'name validation rejects ../x');
    $check($throws(static fn () => course_validate_name('')), 'name validation rejects empty');
    $check($throws(static fn () => course_validate_name("   \t ")), 'name validation rejects blanks');
    $check($throws(static fn () => course_validate_name("a\x01b")), 'name validation rejects control characters');
    $check($throws(static fn () => course_validate_name('a\\b')), 'name validation rejects backslash');
    $check($throws(static fn () => course_validate_name(str_repeat('é', 121))), 'name validation rejects too long names');
    $check(course_validate_name("  Cours   d’anatomie ") === 'Cours d’anatomie', 'name validation trims and collapses spaces');

    $second = course_store_pdf_file($teacherA, $parent, $tempPdf($pdf), 'profond.pdf');
    $deepPath = course_file_meta($second)['path'];
    $filePath = course_file_meta($fileId)['path'];
    course_delete_folder($teacherA, $chapter);
    $check(!is_file($deepPath) && !is_file($filePath), 'deleting a folder removes nested files from disk');
    $check(course_file_meta($second) === null && course_file_meta($fileId) === null && course_folder_for_teacher($teacherA, $parent) === null, 'deleting a folder removes nested rows');
    $check(course_folder_for_teacher($teacherA, $courseId) !== null, 'parent course survives subfolder deletion');

    $rootFile = course_store_pdf_file($teacherA, $courseId, $tempPdf($pdf), 'racine.pdf');
    $rootPath = course_file_meta($rootFile)['path'];
    course_delete_file($teacherA, $rootFile);
    $check(!is_file($rootPath) && course_file_meta($rootFile) === null, 'deleting a file removes row and disk file');

    $lastFile = course_store_pdf_file($teacherA, $courseId, $tempPdf($pdf), 'dernier.pdf');
    $lastPath = course_file_meta($lastFile)['path'];
    course_delete_folder($teacherA, $courseId);
    $count = $pdo->prepare('SELECT COUNT(*) FROM cours_dossier WHERE id_cours = ?');
    $count->execute([$courseId]);
    $check((int) $count->fetchColumn() === 0 && !is_file($lastPath), 'deleting the root deletes the whole course');

    $audits = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE category = ? AND id_audit > ? AND actor_id = ?');
    $audits->execute(['cours', $auditBaseline, $teacherA]);
    $check((int) $audits->fetchColumn() >= 5, 'course actions are written to the audit log');
} catch (Throwable $exception) {
    echo 'fail exception: ' . get_class($exception) . ': ' . $exception->getMessage() . ' (' . basename($exception->getFile()) . ':' . $exception->getLine() . ')' . PHP_EOL;
    $failures[] = 'exception';
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ([$teacherA, $teacherB] as $teacherId) {
        if ($teacherId > 0) {
            $pdo->prepare('DELETE FROM cours_fichier WHERE id_dossier IN (SELECT id_dossier FROM cours_dossier WHERE id_enseignant = ?)')->execute([$teacherId]);
            $pdo->prepare('DELETE FROM cours_dossier WHERE id_enseignant = ? AND id_parent IS NULL')->execute([$teacherId]);
            $pdo->prepare('DELETE FROM enseignant WHERE id_enseignant = ?')->execute([$teacherId]);
        }
    }
    foreach ($students as $studentId) {
        $pdo->prepare('DELETE FROM etudiant WHERE id_etudiant = ?')->execute([$studentId]);
    }
    foreach ([$groupTd, $groupCours, $groupOther] as $groupId) {
        if ($groupId > 0) {
            $pdo->prepare('DELETE FROM groupe WHERE id_groupe = ?')->execute([$groupId]);
        }
    }
    foreach ([$subject, $otherSubject] as $subjectId) {
        if ($subjectId > 0) {
            $pdo->prepare('DELETE FROM matiere WHERE id_mat = ?')->execute([$subjectId]);
        }
    }
    $pdo->prepare('DELETE FROM audit_log WHERE category = ? AND id_audit > ? AND actor_role = ? AND actor_id IN (?, ?)')
        ->execute(['cours', $auditBaseline, 'enseignant', $teacherA, $teacherB]);
    foreach ($tempFiles as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($storage)) {
        foreach (glob($storage . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($storage);
    }
    $leftovers = 0;
    foreach ([
        ['SELECT COUNT(*) FROM enseignant WHERE id_enseignant IN (?, ?)', [$teacherA, $teacherB]],
        ['SELECT COUNT(*) FROM matiere WHERE id_mat IN (?, ?)', [$subject, $otherSubject]],
        ['SELECT COUNT(*) FROM groupe WHERE semestre = ?', [$semester]],
        ['SELECT COUNT(*) FROM etudiant WHERE cin_passeport LIKE ?', ['CLI' . $tag . '%']],
        ['SELECT COUNT(*) FROM audit_log WHERE id_audit > ? AND actor_role = ? AND actor_id IN (?, ?)', [$auditBaseline, 'enseignant', $teacherA, $teacherB]],
    ] as [$sql, $params]) {
        $query = $pdo->prepare($sql);
        $query->execute($params);
        $leftovers += (int) $query->fetchColumn();
    }
    $check($leftovers === 0 && !is_dir($storage), 'fixtures cleaned up (rows and files)');
}

echo $failures === [] ? "ALL PASSED\n" : ('FAILED ' . count($failures) . "\n");
exit($failures === [] ? 0 : 1);
