<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');

if (!is_student()) {
    header('Location: /connexion_etudiant');
    exit;
}

$studentId = (int) ($_SESSION['id_etudiant'] ?? 0);
$year = result_active_year();
$published = result_published_for_student($studentId, $year);
if ($published === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Aucun relevé publié pour le moment.');
}

$pdo = db();
$profileQuery = $pdo->prepare(
    'SELECT e.*, s.nom_spec FROM etudiant e JOIN specialite s ON s.id_spec = e.id_spec WHERE e.id_etudiant = ? LIMIT 1'
);
$profileQuery->execute([$studentId]);
$student = $profileQuery->fetch();
if (!$student) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Dossier introuvable.');
}

$marksBySemester = [];
foreach ($published['marks'] as $mark) {
    $marksBySemester[(string) ($mark['semestre'] ?? '')][] = $mark;
}
uksort($marksBySemester, static function (string $left, string $right): int {
    return ((int) substr($left, 1)) <=> ((int) substr($right, 1));
});

$academicYear = str_contains($year, ' - ') ? $year : str_replace('-', ' - ', $year);
$wantPdf = (string) ($_GET['format'] ?? '') === 'pdf';
$requestedSemester = strtoupper(trim((string) ($_GET['semestre'] ?? '')));
if ($requestedSemester !== '' && !isset($marksBySemester[$requestedSemester])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Semestre introuvable pour ce relevé.');
}

$availableSemesters = array_keys($marksBySemester);
if ($requestedSemester === '' && $availableSemesters !== []) {
    $requestedSemester = (string) $availableSemesters[0];
}

if ($wantPdf && ($requestedSemester === '' || !isset($marksBySemester[$requestedSemester]))) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Choisissez un semestre pour télécharger le relevé.');
}

$semestersToRender = $requestedSemester !== '' && isset($marksBySemester[$requestedSemester])
    ? [$requestedSemester => $marksBySemester[$requestedSemester]]
    : [];

function transcript_number(float $number): string
{
    return number_format($number, 2, ',', '');
}

function transcript_mention(?float $average): string
{
    if ($average === null) {
        return '—';
    }
    if ($average >= 16) {
        return 'Très Bien';
    }
    if ($average >= 14) {
        return 'Bien';
    }
    if ($average >= 12) {
        return 'Assez Bien';
    }
    if ($average >= 10) {
        return 'Passable';
    }
    return 'Ajourné';
}

function semester_label(string $semester): string
{
    return 'Semestre : ' . (int) substr($semester, 1);
}

function transcript_regime(array $elements): string
{
    $regimes = [];
    foreach ($elements as $element) {
        $regime = strtoupper(trim((string) ($element['regime'] ?? '')));
        if ($regime !== '') {
            $regimes[$regime] = true;
        }
    }
    $list = array_keys($regimes);
    return $list === [] ? '—' : implode('/', $list);
}

$decision = result_decision_label((string) $published['decision']);
$situation = match ((string) $published['decision']) {
    'admis', 'admis_par_rachat' => 'Admis(e)',
    'refusee' => 'Refusé(e)',
    default => $decision,
};
$brand = institution_brand();
$nameLines = institution_name_lines();
$logoHtml = $wantPdf
    ? ''
    : '<img src="' . e(institution_logo_src()) . '" alt="" class="transcript-logo">';

ob_start();
foreach ($semestersToRender as $semester => $semesterMarks):
    $units = [];
    foreach ($semesterMarks as $mark) {
        $units[(string) ($mark['code_ue'] ?? '') . '|' . (string) ($mark['description_ue'] ?? '')][] = $mark;
    }

    $semesterWeighted = 0.0;
    $semesterCoefficient = 0.0;
    $semesterCredits = 0.0;
    $semesterSum = 0.0;
    $semesterCount = 0;
    $creditsAcquired = 0.0;
    foreach ($semesterMarks as $semesterMark) {
        $semesterNote = calculated_note($semesterMark) ?? 0.0;
        $semesterWeighted += $semesterNote * (float) ($semesterMark['coeff'] ?? 0);
        $semesterCoefficient += (float) ($semesterMark['coeff'] ?? 0);
        $semesterCredits += (float) ($semesterMark['credit'] ?? 0);
        $creditsAcquired += institution_acquired_credit($semesterNote, (float) ($semesterMark['credit'] ?? 0));
        $semesterSum += $semesterNote;
        $semesterCount++;
    }
    $semesterAverage = institution_general_average(
        $semesterWeighted,
        $semesterCoefficient,
        $semesterCredits,
        $semesterCount,
        $semesterSum
    );
?>
<article class="transcript-paper">
  <div class="sheet">
    <header class="transcript-header">
      <section class="head-left">
        <?= $logoHtml ?>
        <strong>République Tunisienne</strong>
        <span>Ministère de l'Enseignement Supérieur<br>et de la Recherche Scientifique</span>
      </section>
      <section class="transcript-title">
        <div class="title-box">
          <h1>RELEVE DE NOTES</h1>
          <span><?= e($academicYear) ?></span>
        </div>
      </section>
      <section class="head-right">
        <?php foreach ($nameLines as $index => $institutionLine): ?>
          <?= $index === 0 ? '<strong>' . e($institutionLine) . '</strong>' : '<span>' . e($institutionLine) . '</span>' ?>
        <?php endforeach; ?>
        <?php if (trim((string) ($brand['establishment_fr'] ?? '')) !== '' && !in_array($brand['establishment_fr'], $nameLines, true)): ?>
          <span><?= e((string) $brand['establishment_fr']) ?></span>
        <?php endif; ?>
      </section>
    </header>

    <section class="transcript-info">
      <table>
        <tr>
          <td><b>Nom et Prénom :</b> <?= e(trim((string) ($student['nom'] . ' ' . $student['prenom']))) ?></td>
          <td><b>Situation :</b> <?= e($situation) ?></td>
        </tr>
        <tr>
          <td><b>Né le :</b> <?= e(date('d/m/Y', strtotime((string) $student['date_naissance']))) ?> <b>à</b> <?= e((string) $student['lieu_naissance']) ?></td>
          <td><b>N° de CIN :</b> <?= e((string) $student['cin_passeport']) ?> &nbsp; <b>N° d'Inscription :</b> <?= e((string) $student['num_inscription']) ?></td>
        </tr>
        <tr>
          <td><b>Diplôme :</b> <?= e((string) $student['nom_spec']) ?></td>
          <td><b>Niveau d'étude :</b> <?= e(level_label((string) $student['niveau'])) ?></td>
        </tr>
      </table>
    </section>

    <table class="main-grid">
      <colgroup>
        <col style="width:13%">
        <col style="width:3.5%"><col style="width:3.5%"><col style="width:3.5%"><col style="width:3.8%"><col style="width:3.8%"><col style="width:3.8%"><col style="width:3.8%">
        <col style="width:28%">
        <col style="width:4%"><col style="width:4%"><col style="width:5.5%"><col style="width:4.5%"><col style="width:5.5%"><col style="width:4.5%">
      </colgroup>
      <thead>
        <tr class="session-bar"><th colspan="15">Session principale / Session de rattrapage</th></tr>
        <tr class="col-head">
          <th rowspan="2" class="ue">Unités</th>
          <th rowspan="2" class="n">Coef</th>
          <th rowspan="2" class="n">Cr</th>
          <th rowspan="2" class="n">Rég</th>
          <th rowspan="2" class="n">Moy<br>S.P.</th>
          <th rowspan="2" class="n">Cr<br>S.P.</th>
          <th rowspan="2" class="n">Moy<br>S.R.</th>
          <th rowspan="2" class="n">Cr<br>S.R.</th>
          <th colspan="7" class="el-title">Éléments d'enseignement</th>
        </tr>
        <tr class="col-sub">
          <th class="lib">Libellé</th>
          <th class="n">Coef</th>
          <th class="n">Rég</th>
          <th class="n">Moy<br>S.P.</th>
          <th class="n">Cr</th>
          <th class="n">Moy<br>S.R.</th>
          <th class="n">Cr</th>
        </tr>
      </thead>
      <tbody>
        <tr class="semester-bar"><td colspan="15"><?= e(semester_label((string) $semester)) ?></td></tr>
        <?php if ($units === []): ?>
          <tr><td colspan="15" class="empty">Aucune note enregistrée pour ce semestre.</td></tr>
        <?php endif; ?>
        <?php foreach ($units as $unitKey => $elements):
            [, $unitName] = array_pad(explode('|', $unitKey, 2), 2, '');
            $unitCoefficient = array_sum(array_map(static fn(array $item): float => (float) ($item['coeff'] ?? 0), $elements));
            $unitCredits = array_sum(array_map(static fn(array $item): float => (float) ($item['credit'] ?? 0), $elements));
            $unitWeighted = array_sum(array_map(static fn(array $item): float => (calculated_note($item) ?? 0.0) * (float) ($item['coeff'] ?? 0), $elements));
            $unitAverage = $unitCoefficient > 0 ? $unitWeighted / $unitCoefficient : 0.0;
            $unitCreditsAcquired = institution_acquired_credit($unitAverage, (float) $unitCredits);
            $retakeElements = array_values(array_filter(
                $elements,
                static fn(array $item): bool => ($item['note_rattrapage'] ?? null) !== null && $item['note_rattrapage'] !== ''
            ));
            $retakeCoefficient = array_sum(array_map(static fn(array $item): float => (float) ($item['coeff'] ?? 0), $retakeElements));
            $retakeWeighted = array_sum(array_map(static fn(array $item): float => (float) $item['note_rattrapage'] * (float) ($item['coeff'] ?? 0), $retakeElements));
            $retakeAverage = $retakeCoefficient > 0 ? $retakeWeighted / $retakeCoefficient : null;
            $retakeCredits = $retakeAverage !== null && $retakeAverage >= 10 ? $unitCredits : 0.0;
            $rowspan = max(1, count($elements));
            $first = true;
            foreach ($elements as $element):
                $elementNote = calculated_note($element) ?? 0.0;
                $elementCredit = (float) ($element['credit'] ?? 0);
                $elementCreditsAcquired = institution_acquired_credit($elementNote, $elementCredit);
                $elementRetake = ($element['note_rattrapage'] ?? null) !== null && $element['note_rattrapage'] !== ''
                    ? (float) $element['note_rattrapage']
                    : null;
                $elementRetakeCredits = $elementRetake !== null ? institution_acquired_credit($elementRetake, $elementCredit) : null;
        ?>
        <tr>
          <?php if ($first): $first = false; ?>
            <td class="ue" rowspan="<?= $rowspan ?>"><?= e($unitName !== '' ? $unitName : $unitKey) ?></td>
            <td class="n" rowspan="<?= $rowspan ?>"><?= transcript_number((float) $unitCoefficient) ?></td>
            <td class="n" rowspan="<?= $rowspan ?>"><?= transcript_number((float) $unitCredits) ?></td>
            <td class="n" rowspan="<?= $rowspan ?>"><?= e(transcript_regime($elements)) ?></td>
            <td class="n" rowspan="<?= $rowspan ?>"><?= transcript_number($unitAverage) ?></td>
            <td class="n" rowspan="<?= $rowspan ?>"><?= transcript_number($unitCreditsAcquired) ?></td>
            <td class="n" rowspan="<?= $rowspan ?>"><?= $retakeAverage === null ? '—' : transcript_number($retakeAverage) ?></td>
            <td class="n" rowspan="<?= $rowspan ?>"><?= $retakeAverage === null ? '—' : transcript_number($retakeCredits) ?></td>
          <?php endif; ?>
          <td class="lib"><?= e((string) ($element['description_ecue'] ?? '')) ?></td>
          <td class="n"><?= transcript_number((float) ($element['coeff'] ?? 0)) ?></td>
          <td class="n"><?= e(trim((string) ($element['regime'] ?? '')) !== '' ? (string) $element['regime'] : '—') ?></td>
          <td class="n"><?= transcript_number($elementNote) ?></td>
          <td class="n"><?= transcript_number($elementCreditsAcquired) ?></td>
          <td class="n"><?= $elementRetake === null ? '—' : transcript_number($elementRetake) ?></td>
          <td class="n"><?= $elementRetakeCredits === null ? '—' : transcript_number($elementRetakeCredits) ?></td>
        </tr>
        <?php
            endforeach;
        endforeach;
        ?>
        <tr class="total-row">
          <td class="ue"><b>Total</b></td>
          <td class="n"><b><?= transcript_number($semesterCoefficient) ?></b></td>
          <td class="n"><b><?= transcript_number($semesterCredits) ?></b></td>
          <td class="n"></td>
          <td class="n"><b><?= $semesterAverage === null ? '—' : transcript_number((float) $semesterAverage) ?></b></td>
          <td class="n"><b><?= transcript_number($creditsAcquired) ?></b></td>
          <td class="n"></td>
          <td class="n"></td>
          <td colspan="7"></td>
        </tr>
      </tbody>
    </table>

    <section class="transcript-summary">
      <div><b>Moyenne générale :</b> <?= $semesterAverage === null ? '—' : transcript_number((float) $semesterAverage) ?></div>
      <div><b>Crédits Acquis :</b> <?= transcript_number($creditsAcquired) ?></div>
      <div><b>Mention :</b> <?= e(strtoupper(transcript_mention($semesterAverage !== null ? (float) $semesterAverage : null))) ?></div>
      <div><b>Décision de Jury d'examen :</b> <?= e($decision) ?></div>
    </section>

    <footer class="transcript-footer">
      <div class="nb">
        <p><b>N.B :</b></p>
        <p>- Une unité d'enseignement est acquise si sa moyenne est supérieure ou égale à 10/20.</p>
        <p>- Les crédits d'une unité d'enseignement sont acquis lorsque l'unité est validée.</p>
        <p>- S.P. = Session principale ; S.R. = Session de rattrapage.</p>
        <p>- Le calcul des moyennes suit la formule définie par l'établissement.</p>
      </div>
      <div class="sign">
        <p><?= e(institution_place_fr()) ?></p>
        <p><b>Le secrétaire général</b></p>
        <p><?= e((string) $brand['sec_generale']) ?></p>
        <div class="stamp-box">Cachet et signature</div>
      </div>
    </footer>
  </div>
</article>
<?php
endforeach;
$transcriptBody = (string) ob_get_clean();
if (trim($transcriptBody) === '') {
    $transcriptBody = '<article class="transcript-paper"><div class="sheet"><p class="empty">Aucune note n\'est encore enregistrée.</p></div></article>';
}

$transcriptCss = '@page{size:A4 portrait;margin:8mm 12mm}'
    . 'html,body{margin:0;padding:0}'
    . '.transcript-paper{width:100%;margin:0;background:#fff;color:#000;font-family:DejaVu Sans,Helvetica,sans-serif;font-size:8px;page-break-inside:avoid}'
    . '.sheet{border:1.2px solid #111;padding:2.5mm 3mm}'
    . '.transcript-header{display:table;width:100%;table-layout:fixed;margin:0 0 2mm}'
    . '.transcript-header > section{display:table-cell;vertical-align:middle;text-align:center;padding:0 1mm}'
    . '.head-left,.head-right{width:31%}'
    . '.transcript-title{width:38%}'
    . '.transcript-header strong{display:block;font-size:9px;text-transform:uppercase;line-height:1.15}'
    . '.transcript-header span{display:block;font-size:7px;font-weight:700;line-height:1.15;margin-top:1px}'
    . '.transcript-logo{width:32px;height:14px;object-fit:contain;margin-bottom:1px}'
    . '.title-box{display:inline-block;border:1.4px solid #111;padding:2.5mm 4mm;min-width:70%}'
    . '.title-box h1{margin:0;font-size:14px;font-weight:800;line-height:1}'
    . '.title-box span{display:block;margin-top:1.5mm;font-size:11px;font-weight:700}'
    . '.transcript-info{margin:0 0 2mm}'
    . '.transcript-info table{width:100%;border-collapse:collapse;table-layout:fixed}'
    . '.transcript-info td{border:1px solid #111;padding:1.6mm 2mm;font-size:8px;vertical-align:middle;width:50%}'
    . '.main-grid{width:100%;border-collapse:collapse;table-layout:fixed;font-size:7px}'
    . '.main-grid th,.main-grid td{border:1px solid #111;vertical-align:middle;text-align:center;padding:2px 1px;line-height:1.15;overflow:hidden}'
    . '.main-grid th.ue,.main-grid td.ue{text-align:left;padding:2px 3px;font-weight:700;font-size:7px}'
    . '.main-grid th.lib,.main-grid td.lib{text-align:left;padding:2px 3px;font-size:7px}'
    . '.main-grid th.n,.main-grid td.n{font-size:6.5px;white-space:nowrap;padding:2px 0}'
    . '.session-bar th{background:#d0d0d0;font-size:9px;font-weight:800;padding:3px;text-align:center}'
    . '.col-head th,.col-sub th{background:#e4e4e4;font-size:6px;line-height:1.05;padding:2px 0}'
    . '.col-head .el-title{background:#d8d8d8;font-size:7.5px;padding:2px}'
    . '.semester-bar td{background:#cfcfcf;font-weight:800;text-align:left;padding:3px 4px;font-size:8.5px}'
    . '.total-row td{background:#efefef}'
    . '.empty{text-align:center;font-style:italic;padding:8px}'
    . '.transcript-summary{display:table;width:100%;table-layout:fixed;border-collapse:collapse;margin-top:0;border:1.5px solid #111;border-top:0}'
    . '.transcript-summary div{display:table-cell;width:25%;border-right:1px solid #111;padding:3px 4px;font-size:8px;vertical-align:middle}'
    . '.transcript-summary div:last-child{border-right:0}'
    . '.transcript-footer{display:table;width:100%;table-layout:fixed;margin-top:3mm}'
    . '.transcript-footer .nb,.transcript-footer .sign{display:table-cell;vertical-align:top}'
    . '.transcript-footer .nb{width:62%;font-size:6.5px;line-height:1.3;padding-right:3mm}'
    . '.transcript-footer .nb p{margin:0 0 1px}'
    . '.transcript-footer .sign{width:38%;text-align:center;font-size:8.5px;line-height:1.25}'
    . '.transcript-footer .sign p{margin:0 0 1px}'
    . '.stamp-box{margin:6mm auto 0;width:28mm;height:22mm;border:1px dashed #777;font-size:6.5px;font-style:italic;line-height:22mm}';

if ($wantPdf) {
    require_once root_path('vendor/autoload.php');
    $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>'
        . $transcriptCss
        . '</style></head><body>'
        . $transcriptBody
        . '</body></html>';
    $dompdf = new \Dompdf\Dompdf([
        'isRemoteEnabled' => false,
        'isHtml5ParserEnabled' => true,
        'defaultFont' => 'DejaVu Sans',
    ]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($student['nom'] ?? 'etudiant')) ?: 'etudiant';
    $safeSemester = preg_replace('/[^A-Za-z0-9_-]+/', '', $requestedSemester) ?: 'S';
    $fileName = 'releve_notes_' . $safeName . '_' . $safeSemester . '_' . preg_replace('/\s+/', '', $year) . '.pdf';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: private, no-store');
    echo $dompdf->output();
    exit;
}

$semesterOptions = array_keys($marksBySemester);
$defaultSemester = $requestedSemester !== '' ? $requestedSemester : ($semesterOptions[0] ?? '');
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Relevé de notes | <?= e(institution_name_inline()) ?></title>
  <style>
    body{margin:0;background:#111;color:#f5f5f5;font-family:system-ui,sans-serif}
    .transcript-toolbar{display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between;max-width:210mm;margin:1rem auto;padding:0 1rem}
    .transcript-toolbar h1{margin:0;font-size:1.35rem}
    .transcript-toolbar p{margin:.25rem 0 0;color:#9aa3ad;font-size:.9rem}
    .transcript-actions{display:flex;flex-wrap:wrap;gap:.55rem;align-items:center}
    .transcript-actions a,.transcript-actions button{display:inline-flex;align-items:center;gap:.4rem;padding:.7rem 1.05rem;border-radius:999px;border:0;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}
    .transcript-actions .is-back{background:transparent;color:#d7e0e8;border:1px solid rgba(215,224,232,.35)}
    .transcript-actions .is-print{background:#f3d7a1;color:#1c1408}
    .transcript-actions label{display:inline-flex;align-items:center;gap:.4rem;color:#d7e0e8;font-size:.9rem}
    .transcript-actions select{min-height:2.5rem;border-radius:.7rem;border:1px solid rgba(215,224,232,.35);background:#1b1f24;color:#f5f5f5;padding:.45rem .7rem;font:inherit}
    <?= str_replace('DejaVu Sans,Helvetica,sans-serif', '"Times New Roman",Times,serif', $transcriptCss) ?>
    .transcript-paper{max-width:210mm;margin:0 auto 2rem;box-shadow:0 18px 45px #0008}
    .transcript-header{display:grid;grid-template-columns:1.1fr 1.2fr 1.1fr}
    .transcript-header > section{display:flex;flex-direction:column;justify-content:center;align-items:center}
    .transcript-info table{display:table}
    .transcript-summary{display:grid;grid-template-columns:repeat(4,1fr)}
    .transcript-summary div{display:block;width:auto}
    .transcript-footer{display:grid;grid-template-columns:1.6fr 1fr;gap:12px}
    .transcript-footer .nb,.transcript-footer .sign{display:block;width:auto}
    @media print{@page{size:A4 portrait;margin:8mm 10mm}body{background:#fff!important}.no-print{display:none!important}.transcript-paper{box-shadow:none;margin:0}}
  </style>
</head>
<body>
  <div class="transcript-toolbar no-print">
    <div>
      <h1>Relevé de notes</h1>
      <p><?= e(trim((string) ($student['prenom'] . ' ' . $student['nom']))) ?> · <?= e($academicYear) ?></p>
    </div>
    <div class="transcript-actions">
      <a class="is-back" href="/dashboard?panel=notes">Retour</a>
      <?php if ($semesterOptions !== []): ?>
        <label for="transcriptSemester">Semestre
          <select id="transcriptSemester">
            <?php foreach ($semesterOptions as $option): ?>
              <option value="<?= e($option) ?>"<?= $option === $defaultSemester ? ' selected' : '' ?>><?= e('Semestre ' . (int) substr($option, 1)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <a class="is-print" id="transcriptPdfLink" href="/releve_etudiant?format=pdf&amp;semestre=<?= e($defaultSemester) ?>">Télécharger PDF</a>
      <?php endif; ?>
      <button class="is-print" type="button" onclick="window.print()">Imprimer</button>
    </div>
  </div>
  <?= $transcriptBody ?>
  <?php if ($semesterOptions !== []): ?>
  <script>
    (function () {
      var select = document.getElementById('transcriptSemester');
      var link = document.getElementById('transcriptPdfLink');
      if (!select || !link) return;
      select.addEventListener('change', function () {
        var semester = select.value;
        link.href = '/releve_etudiant?format=pdf&semestre=' + encodeURIComponent(semester);
        var url = new URL(window.location.href);
        url.searchParams.set('semestre', semester);
        url.searchParams.delete('format');
        window.location.assign(url.toString());
      });
    })();
  </script>
  <?php endif; ?>
</body>
</html>
