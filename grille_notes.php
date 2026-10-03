<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_teacher_or_admin();

$groupId = filter_input(INPUT_GET, 'group', FILTER_VALIDATE_INT) ?: 0;
$subjectId = filter_input(INPUT_GET, 'subject', FILTER_VALIDATE_INT) ?: 0;
if ($groupId < 1 || $subjectId < 1) {
    http_response_code(400);
    exit('Choisissez un groupe et une matière.');
}

$pdo = db();
$groupQuery = $pdo->prepare('SELECT id_groupe, semestre, type_groupe, numero FROM groupe WHERE id_groupe = ?');
$groupQuery->execute([$groupId]);
$group = $groupQuery->fetch();
$subjectQuery = $pdo->prepare('SELECT id_mat, code_ecue, description_ecue, semestre, volume_tp FROM matiere WHERE id_mat = ?');
$subjectQuery->execute([$subjectId]);
$subject = $subjectQuery->fetch();
if (!$group || $group['type_groupe'] !== 'Cours' || !$subject || $subject['semestre'] !== $group['semestre']) {
    http_response_code(404);
    exit('Groupe ou matière introuvable.');
}
if (is_teacher()) {
    $assignment = $pdo->prepare('SELECT 1 FROM enseignant_affectation WHERE id_enseignant = ? AND id_mat = ? AND id_groupe = ?');
    $assignment->execute([current_teacher_id(), $subjectId, $groupId]);
    if (!$assignment->fetchColumn()) {
        http_response_code(403);
        exit('Cette feuille n’est pas dans vos affectations.');
    }
}

$members = $pdo->prepare(
    'SELECT e.id_etudiant, e.nom, e.prenom, e.cin_passeport
     FROM groupe_etudiant ge
     JOIN etudiant e ON e.id_etudiant = ge.id_etudiant
     WHERE ge.id_groupe = ?
     ORDER BY e.nom, e.prenom'
);
$members->execute([$groupId]);
$notes = $pdo->prepare('SELECT * FROM note WHERE id_mat = ? AND semestre = ?');
$notes->execute([$subjectId, $subject['semestre']]);
$notesByStudent = [];
foreach ($notes->fetchAll() as $note) {
    $notesByStudent[(int) $note['id_etudiant']] = $note;
}

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$mark = static function (?array $note, string $field): string {
    if (!$note || $note[$field] === null || $note[$field] === '') {
        return '—';
    }
    return number_format((float) $note[$field], 2, ',', '');
};
$rows = '';
$index = 0;
foreach ($members->fetchAll() as $member) {
    $index++;
    $note = $notesByStudent[(int) $member['id_etudiant']] ?? null;
    if ($note) {
        $note['volume_tp'] = $subject['volume_tp'];
    }
    $result = $note ? calculated_note($note) : null;
    $rows .= '<tr><td>' . $index . '</td><td>' . $escape(trim($member['nom'] . ' ' . $member['prenom'])) . '</td><td>'
        . $escape((string) $member['cin_passeport']) . '</td><td>' . $escape($mark($note, 'note_cours')) . '</td><td>'
        . $escape($mark($note, 'note_td')) . '</td><td>' . $escape($mark($note, 'note_tp')) . '</td><td>'
        . $escape($mark($note, 'note')) . '</td><td>' . $escape($mark($note, 'note_rattrapage')) . '</td><td>'
        . $escape($result === null ? '—' : number_format($result, 2, ',', '')) . '</td></tr>';
}
if ($rows === '') {
    $rows = '<tr><td colspan="9">Aucun étudiant dans ce groupe.</td></tr>';
}

$title = $subject['code_ecue'] . ' — ' . $subject['description_ecue'];
$subtitle = $group['semestre'] . ' · Cours ' . $group['numero'];
$html = '<html><head><meta charset="UTF-8"><style>
  body { font-family: DejaVu Sans, sans-serif; color: #17233d; font-size: 11px; }
  h1 { font-size: 18px; margin: 0 0 2px; }
  h2 { font-size: 13px; margin: 0 0 10px; font-weight: normal; }
  .muted { color: #5c6b83; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #c5d0de; padding: 5px 6px; }
  th { background: #e8eef6; text-align: left; }
  td:nth-child(n+4), th:nth-child(n+4) { text-align: center; width: 58px; }
</style></head><body>
  <p class="muted">Université privée des sciences infirmières et de santé de Gabès</p>
  <h1>Feuille de notes</h1>
  <h2>' . $escape($title) . ' · ' . $escape($subtitle) . '</h2>
  <p class="muted">CC, Examen, TP, finale manuelle et rattrapage. Le résultat suit le calcul déjà utilisé dans les relevés. Imprimé le ' . $escape(date('d/m/Y')) . '.</p>
  <table>
    <thead><tr><th>#</th><th>Étudiant</th><th>CIN</th><th>CC</th><th>Examen</th><th>TP</th><th>Finale</th><th>Rattrapage</th><th>Résultat</th></tr></thead>
    <tbody>' . $rows . '</tbody>
  </table>
</body></html>';

$dompdf = new \Dompdf\Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$filename = 'notes_' . $subject['code_ecue'] . '_' . $group['semestre'] . '_cours' . $group['numero'] . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
