<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_teacher_or_admin();
require_once __DIR__ . '/vendor/autoload.php';

$teacherId = is_teacher()
    ? current_teacher_id()
    : (filter_input(INPUT_GET, 'id_enseignant', FILTER_VALIDATE_INT) ?: 0);
$groupId = filter_input(INPUT_GET, 'id_groupe', FILTER_VALIDATE_INT) ?: 0;
$subjectId = filter_input(INPUT_GET, 'id_mat', FILTER_VALIDATE_INT) ?: 0;
if (!$teacherId || !$groupId || !$subjectId) {
    http_response_code(400);
    exit('Enseignant, groupe et matière requis.');
}

$pdo = db();
$query = $pdo->prepare(
    'SELECT e.nom, e.prenom, m.semestre, m.code_ecue, m.description_ecue,
            g.type_groupe, g.numero, parent.numero AS parent_numero,
            MOD(g.numero - 1, 2) + 1 AS td_numero
     FROM enseignant_affectation a
     JOIN enseignant e ON e.id_enseignant = a.id_enseignant
     JOIN matiere m ON m.id_mat = a.id_mat
     JOIN groupe g ON g.id_groupe = a.id_groupe
     LEFT JOIN groupe parent ON parent.id_groupe = g.id_groupe_parent
     WHERE a.id_enseignant = ? AND a.id_groupe = ? AND a.id_mat = ?
       AND g.type_groupe IN ("Cours", "TD")'
);
$query->execute([$teacherId, $groupId, $subjectId]);
$assignment = $query->fetch();
if (!$assignment) {
    http_response_code(403);
    exit('Cette affectation n’existe pas.');
}

$studentsQuery = $pdo->prepare(
    'SELECT e.nom, e.prenom
     FROM groupe_etudiant ge
     JOIN etudiant e ON e.id_etudiant = ge.id_etudiant
     WHERE ge.id_groupe = ?
     ORDER BY e.nom, e.prenom'
);
$studentsQuery->execute([$groupId]);
$students = $studentsQuery->fetchAll();

$dompdf = new \Dompdf\Dompdf();
$fontMetrics = $dompdf->getFontMetrics();
$font = $fontMetrics->getFont('Helvetica', 'normal');
$longestNameWidth = 0.0;
foreach ($students as $student) {
    $studentName = trim($student['nom'] . ' ' . $student['prenom']);
    $longestNameWidth = max($longestNameWidth, $fontMetrics->getTextWidth($studentName, $font, 9));
}

$pageContentWidth = 842 - 35;
$numberColumnWidth = 28;
$nameColumnWidth = max(54.0, ceil($longestNameWidth + 12));
$maximumEqualColumns = (int) floor(($pageContentWidth - $numberColumnWidth) / $nameColumnWidth);
$attendanceColumnCount = max(0, min(12, $maximumEqualColumns - 1));
$tableWidth = $numberColumnWidth + ($attendanceColumnCount + 1) * $nameColumnWidth;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$logoMarkup = '';
$logoPath = __DIR__ . '/images/attendance-logo.jpg';
if (is_file($logoPath)) {
    $logoData = base64_encode((string) file_get_contents($logoPath));
    $logoMarkup = '<img class="logo" src="data:image/jpeg;base64,' . $logoData . '" alt="">';
}
$fileName = 'Feuille_presence_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $assignment['code_ecue'] . '_' . $assignment['type_groupe'] . $assignment['numero']) . '.pdf';

$columnGroup = '<col style="width:' . $numberColumnWidth . 'pt"><col style="width:' . $nameColumnWidth . 'pt">';
for ($column = 0; $column < $attendanceColumnCount; $column++) {
    $columnGroup .= '<col style="width:' . $nameColumnWidth . 'pt">';
}

$attendanceHeaders = str_repeat('<th></th>', $attendanceColumnCount);
$attendanceCells = str_repeat('<td></td>', $attendanceColumnCount);
$studentRows = '';
foreach ($students as $index => $student) {
    $studentName = trim($student['nom'] . ' ' . $student['prenom']);
    $studentRows .= '<tr><td class="number">' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)
        . '</td><td class="student-name">' . $escape($studentName) . '</td>' . $attendanceCells . '</tr>';
}

$academicYear = (int) date('Y') . '-' . ((int) date('Y') + 1);
$groupLabel = $assignment['semestre'] . ' · ' . ($assignment['type_groupe'] === 'TD'
    ? 'Cours ' . $assignment['parent_numero'] . ' · TD ' . $assignment['td_numero']
    : 'Cours ' . $assignment['numero']);
$subjectLabel = $assignment['code_ecue'] . ' — ' . $assignment['description_ecue'];
$teacherLabel = trim($assignment['nom'] . ' ' . $assignment['prenom']);
$html = '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><style>
    @page { size: A4 landscape; margin: 17.5pt; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 9pt; color: #111; }
    .header { text-align: center; margin-bottom: 12pt; }
    .logo { display: block; width: 390pt; height: 80pt; object-fit: contain; margin: 0 auto 5pt; }
    h1 { font-size: 15pt; margin: 2pt 0; }
    .year { font-size: 10pt; font-weight: bold; }
    .meta { margin: 4pt 0; }
    table { border-collapse: collapse; table-layout: fixed; width: ' . $tableWidth . 'pt; margin-top: 8pt; }
    th, td { border: 1px solid #222; padding: 4pt; height: 30pt; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th { height: 32pt; text-align: center; vertical-align: middle; font-weight: bold; }
    td { vertical-align: middle; }
    .number { text-align: center; }
    .student-name { white-space: nowrap; }
    .footer { margin-top: 10pt; font-size: 8pt; }
    .institution { text-align: center; margin-top: 8pt; font-size: 8pt; }
    .signature { page-break-inside: avoid; width: 180pt; margin: 20pt 0 0 auto; text-align: center; font-size: 9pt; }
    .signature-line { height: 38pt; border-bottom: 1px solid #222; margin-bottom: 5pt; }
    </style></head><body>
    <div class="header">' . $logoMarkup . '<h1>FEUILLE DE PRÉSENCE</h1><div class="year">(Année Universitaire ' . $escape($academicYear) . ')</div></div>
    <div class="meta">Groupe : ' . $escape($groupLabel) . ' | Matière : ' . $escape($subjectLabel) . '</div>
    <div class="meta">Nom de l’enseignant : ' . $escape($teacherLabel) . '</div>
    <table><colgroup>' . $columnGroup . '</colgroup><thead><tr><th>N°</th><th>Nom &amp; Prénom</th>' . $attendanceHeaders . '</tr></thead><tbody>'
    . $studentRows .
    '</tbody></table>
    <div class="footer">NB : Les enseignants sont priés de faire l’appel et de relever les absences.</div>
    <div class="institution">' . $escape(institution_name_inline()) . '</div>
    <div class="signature"><div class="signature-line"></div><div>Signature de l’enseignant</div></div>
    </body></html>';

$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (($_GET['pdf'] ?? '') === 'preview' ? 'inline' : 'attachment') . '; filename="' . $fileName . '"');
$dompdf->stream($fileName, ['Attachment' => (($_GET['pdf'] ?? '') === 'preview' ? 0 : 1)]);
