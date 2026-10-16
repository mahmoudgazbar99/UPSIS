<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_permission('students.view');


$cin = trim((string) ($_GET['cin'] ?? ''));
$transcriptYear = trim((string) ($_GET['year'] ?? ''));
$student = null;
$marksBySemester = [];
$error = '';
$academicYear = $transcriptYear !== '' ? str_replace('-', ' - ', $transcriptYear) : date('Y') . ' - ' . ((int) date('Y') + 1);
$hasRetakeColumn = (bool) db()->query("SHOW COLUMNS FROM note LIKE 'note_rattrapage'")->fetch();

if ($cin !== '') {
    $studentQuery = db()->prepare('SELECT e.*, s.nom_spec FROM etudiant e JOIN specialite s ON s.id_spec = e.id_spec WHERE e.cin_passeport = :cin OR e.nom LIKE :search_nom OR e.prenom LIKE :search_prenom OR e.num_inscription = :registration LIMIT 1');
    $studentQuery->execute(['cin' => $cin, 'search_nom' => '%' . $cin . '%', 'search_prenom' => '%' . $cin . '%', 'registration' => $cin]);
    $student = $studentQuery->fetch();

    if (!$student) {
        $error = 'Aucun étudiant ne correspond à ce CIN / passeport.';
    } else {
        $retakeSelect = $hasRetakeColumn ? 'n.note_rattrapage' : 'NULL AS note_rattrapage';
        if ($transcriptYear !== '') {
            $archiveQuery = db()->prepare('SELECT student_payload, transcript_payload, niveau, nom_spec FROM etudiant_archive WHERE cin_passeport = ? AND annee_scolaire = ? LIMIT 1');
            $archiveQuery->execute([$student['cin_passeport'], $transcriptYear]);
            $archive = $archiveQuery->fetch();
        }
        if ($transcriptYear !== '' && empty($archive)) {
            $error = 'Aucun relevé archivé trouvé pour cette année scolaire.';
        } elseif (!empty($archive)) {
            $archivedStudent = json_decode((string) $archive['student_payload'], true);
            if (is_array($archivedStudent)) $student = array_merge($student, $archivedStudent, ['nom_spec' => $archive['nom_spec']]);
            $archivedMarks = json_decode((string) $archive['transcript_payload'], true);
            if (!is_array($archivedMarks)) $archivedMarks = [];
        } else {
            $marksQuery = db()->prepare('SELECT n.semestre, n.note, n.note_cours, n.note_td, n.note_tp, ' . $retakeSelect . ', m.code_ue, m.description_ue, m.code_ecue, m.description_ecue, m.coeff, m.credit, m.regime, m.volume_tp FROM note n JOIN matiere m ON m.id_mat = n.id_mat WHERE n.id_etudiant = :student_id ORDER BY FIELD(n.semestre, "S1", "S2", "S3", "S4", "S5", "S6"), m.code_ue, m.code_ecue');
            $marksQuery->execute(['student_id' => $student['id_etudiant']]);
            $archivedMarks = $marksQuery->fetchAll();
        }
        if ($error === '') {
            foreach ($archivedMarks as $mark) {
                $marksBySemester[$mark['semestre']][] = $mark;
            }
        }
    }
}

function transcript_number(float $number): string {
    return number_format($number, 2, ',', '');
}

function transcript_mention(?float $average): string {
    if ($average === null) return '—';
    if ($average >= 16) return 'Très bien';
    if ($average >= 14) return 'Bien';
    if ($average >= 12) return 'Assez bien';
    if ($average >= 10) return 'Passable';
    return 'Ajourné';
}

function semester_label(string $semester): string {
    return 'Semestre ' . (int) substr($semester, 1);
}

page_head('Relevé de notes', 'transcript');
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print"><div><p class="eyebrow">Scolarité</p><h1 class="display-6 fw-bold mb-0">Relevé de notes</h1></div></div>
<div class="card app-card no-print mb-4"><div class="card-body p-4"><form class="row g-3 align-items-end" method="get"><div class="col-md-6"><label class="form-label" for="cin">Étudiant</label><input class="form-control form-control-lg" id="cin" name="cin" value="<?= e($cin) ?>" placeholder="Nom, CIN ou inscription" required></div><div class="col-md-3"><label class="form-label" for="year">Année archivée (optionnel)</label><input class="form-control" id="year" name="year" value="<?= e($transcriptYear) ?>" placeholder="2025-2026"></div><div class="col-md-3"><button class="btn btn-primary btn-lg w-100">Générer</button></div></form><?php if ($error): ?><div class="alert alert-danger mt-3 mb-0"><?= e($error) ?></div><?php endif; ?></div></div>

<?php if ($student && $error === ''):
    $weightedTotal = 0.0;
    $coeffTotal = 0.0;
    $creditsTotal = 0.0;
    $creditsAcquired = 0.0;
    $averageSum = 0.0;
    $averageCount = 0;
    foreach ($marksBySemester as $semesterMarks) foreach ($semesterMarks as $mark) {
        $finalNote = calculated_note($mark) ?? 0.0;
        $weightedTotal += $finalNote * (float) $mark['coeff'];
        $coeffTotal += (float) $mark['coeff'];
        $creditsTotal += (float) $mark['credit'];
        $creditsAcquired += institution_acquired_credit($finalNote, (float) $mark['credit']);
        $averageSum += $finalNote;
        $averageCount++;
    }
    $generalAverage = institution_general_average($weightedTotal, $coeffTotal, $creditsTotal, $averageCount, $averageSum);
    $decision = $generalAverage === null ? 'En attente de notes' : ($generalAverage >= 10 ? 'Admis' : 'Ajourné');
?>
<article class="transcript-paper">
  <header class="transcript-header">
    <section><img src="<?= e(institution_logo_src()) ?>" alt="Logo institutionnel" class="transcript-logo"><strong>République Tunisienne</strong><span>Ministère de l'Enseignement Supérieur<br>et de la Recherche Scientifique</span></section>
    <section class="transcript-title"><h1>RELEVÉ DE NOTES</h1><span><?= e($academicYear) ?></span></section>
    <section><?php foreach (institution_name_lines() as $index => $institutionLine): ?><?= $index === 0 ? '<strong>' . e($institutionLine) . '</strong>' : '<span>' . e($institutionLine) . '</span>' ?><?php endforeach; ?></section>
  </header>
  <section class="transcript-info">
    <div><p><b>Nom et Prénom :</b> <?= e($student['nom'] . ' ' . $student['prenom']) ?></p><p><b>Né(e) le :</b> <?= e(date('d/m/Y', strtotime($student['date_naissance']))) ?> <b>à :</b> <?= e($student['lieu_naissance']) ?></p><p><b>Diplôme / Spécialité :</b> <?= e($student['nom_spec']) ?></p></div>
    <div><p><b>Situation :</b> <?= e($decision === 'Admis' ? 'Admis(e)' : ($decision === 'Ajourné' ? 'Redoublant(e)' : $decision)) ?></p><p><b>N° de CIN :</b> <?= e($student['cin_passeport']) ?></p><p><b>N° d'Inscription :</b> <?= e($student['num_inscription']) ?></p><p><b>Niveau d'étude :</b> <?= e(level_label($student['niveau'])) ?></p></div>
  </section>

  <?php if (!$marksBySemester): ?><div class="transcript-empty">Aucune note n'est encore enregistrée pour cet étudiant.</div><?php endif; ?>
  <?php foreach ($marksBySemester as $semester => $semesterMarks):
      $units = [];
      foreach ($semesterMarks as $mark) $units[$mark['code_ue'] . '|' . $mark['description_ue']][] = $mark;
      $semesterWeighted = 0.0;
      $semesterCoefficient = 0.0;
      $semesterCredits = 0.0;
      $semesterSum = 0.0;
      $semesterCount = 0;
      foreach ($semesterMarks as $semesterMark) {
          $semesterNote = calculated_note($semesterMark) ?? 0.0;
          $semesterWeighted += $semesterNote * (float) $semesterMark['coeff'];
          $semesterCoefficient += (float) $semesterMark['coeff'];
          $semesterCredits += (float) $semesterMark['credit'];
          $semesterSum += $semesterNote;
          $semesterCount++;
      }
      $semesterAverage = institution_general_average($semesterWeighted, $semesterCoefficient, $semesterCredits, $semesterCount, $semesterSum);
  ?>
  <section class="semester-block">
    <h2><?= e(semester_label($semester)) ?><span class="semester-average">Moyenne du semestre : <?= $semesterAverage === null ? '—' : transcript_number($semesterAverage) ?></span></h2>
    <table class="transcript-table"><thead><tr><th class="ue-col">Unités d'enseignement / Éléments d'enseignement</th><th>Coef</th><th>Cr</th><th>Rég.</th><th colspan="2">Session principale</th><th colspan="2">Session de rattrapage</th></tr><tr><th></th><th></th><th></th><th></th><th>Moy.</th><th>Cr. Acq.</th><th>Moy.</th><th>Cr. Acq.</th></tr></thead><tbody>
      <?php foreach ($units as $unitKey => $elements):
          [, $unitName] = explode('|', $unitKey, 2);
          $unitCoefficient = array_sum(array_column($elements, 'coeff'));
          $unitCredits = array_sum(array_column($elements, 'credit'));
          $unitWeighted = array_sum(array_map(fn(array $item): float => (calculated_note($item) ?? 0.0) * (float) $item['coeff'], $elements));
          $unitAverage = $unitCoefficient > 0 ? $unitWeighted / $unitCoefficient : 0;
          $unitCreditsAcquired = institution_acquired_credit($unitAverage, (float) $unitCredits);
          $retakeElements = array_filter($elements, fn(array $item): bool => $item['note_rattrapage'] !== null);
          $retakeCoefficient = array_sum(array_column($retakeElements, 'coeff'));
          $retakeWeighted = array_sum(array_map(fn(array $item): float => (float) $item['note_rattrapage'] * (float) $item['coeff'], $retakeElements));
          $retakeAverage = $retakeCoefficient > 0 ? $retakeWeighted / $retakeCoefficient : null;
          $rowspan = count($elements) + 1;
      ?>
        <tr class="ue-row"><td rowspan="<?= $rowspan ?>"><b><?= e($unitName ?: $unitKey) ?></b><br><small><?= e(explode('|', $unitKey, 2)[0]) ?></small></td><td rowspan="<?= $rowspan ?>"><?= transcript_number((float) $unitCoefficient) ?></td><td rowspan="<?= $rowspan ?>"><?= transcript_number((float) $unitCredits) ?></td><td rowspan="<?= $rowspan ?>">—</td><td rowspan="<?= $rowspan ?>"><?= transcript_number($unitAverage) ?></td><td rowspan="<?= $rowspan ?>"><?= transcript_number($unitCreditsAcquired) ?></td><td rowspan="<?= $rowspan ?>"><?= $retakeAverage === null ? '—' : transcript_number($retakeAverage) ?></td><td rowspan="<?= $rowspan ?>"><?= $retakeAverage !== null && $retakeAverage >= 10 ? transcript_number((float) $unitCredits) : '—' ?></td></tr>
        <?php foreach ($elements as $element): ?><tr><td class="element-name"><span><?= e($element['description_ecue']) ?></span><small><?= e($element['code_ecue']) ?> · Coef <?= transcript_number((float) $element['coeff']) ?> · <?= e($element['regime']) ?> · <b>Moyenne : <?= transcript_number(calculated_note($element) ?? 0.0) ?></b><?= $element['note_rattrapage'] !== null ? ' · Rattrapage ' . transcript_number((float) $element['note_rattrapage']) : '' ?></small></td></tr><?php endforeach; ?>
      <?php endforeach; ?>
    </tbody></table>
  </section>
  <?php endforeach; ?>

  <section class="transcript-summary"><div><b>Moyenne générale :</b> <?= $generalAverage === null ? '—' : transcript_number($generalAverage) ?></div><div><b>Crédits totaux :</b> <?= transcript_number($creditsTotal) ?></div><div><b>Crédits acquis :</b> <?= transcript_number($creditsAcquired) ?></div><div><b>Mention :</b> <?= e(transcript_mention($generalAverage)) ?></div><div><b>Décision de jury :</b> <?= e($decision) ?></div></section>
  <footer class="transcript-footer"><p><b>NB :</b> Le calcul des moyennes et des crédits est effectué selon la formule définie par l’établissement.</p><div><p><?= e(institution_place_fr()) ?></p><strong>Le secrétaire général</strong><b><?= e(institution_brand()['sec_generale']) ?></b><span>Signature et cachet</span></div></footer>
</article>
<?php endif; ?>

<style>
.transcript-paper{max-width:210mm;min-height:297mm;margin:0 auto 2rem;background:#fff;color:#111;padding:8mm 9mm;font-family:"Times New Roman",serif;font-size:11px;box-shadow:0 18px 45px #0008}.transcript-header{display:grid;grid-template-columns:1.25fr 1.25fr 1.25fr;border:1.5px solid #151515;text-align:center;min-height:53px}.transcript-header section{display:flex;flex-direction:column;justify-content:center;align-items:center;padding:4px;border-right:1px solid #151515;line-height:1.13}.transcript-header section:last-child{border:0}.transcript-header strong{font-size:12px;text-transform:uppercase}.transcript-header span{font-weight:700;font-size:10px}.transcript-logo{width:46px;height:22px;object-fit:contain;margin-bottom:1px}.transcript-title h1{font-size:23px;line-height:1;margin:0 0 4px;font-weight:800}.transcript-title span{font-size:15px}.transcript-info{display:grid;grid-template-columns:2.15fr 1fr;border:1px solid #151515;border-top:0;padding:4px 6px;line-height:1.2}.transcript-info p{margin:0 0 3px;font-size:12px}.transcript-empty{border:1px solid #151515;border-top:0;text-align:center;padding:12px;font-style:italic}.semester-block h2{font-size:13px;border:1px solid #151515;border-bottom:0;margin:0;padding:3px 5px;background:#f0f0f0;display:flex;justify-content:space-between;align-items:center}.semester-average{font-size:11px;font-weight:700}.transcript-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:10px}.transcript-table th,.transcript-table td{border:1px solid #151515;padding:2px 3px;vertical-align:middle;text-align:center;line-height:1.05}.transcript-table th{background:#d9d9d9;font-weight:700}.transcript-table th.ue-col{width:42%;text-align:left}.transcript-table th:not(.ue-col){width:8.28%}.transcript-table .ue-row td{background:#f7f7f7}.transcript-table .element-name{text-align:left;padding-left:8px}.element-name span{display:block}.element-name small{display:block;font-size:8px;color:#444;margin-top:2px}.transcript-summary{display:grid;grid-template-columns:repeat(5,1fr);border:1px solid #151515;border-top:0}.transcript-summary div{padding:5px 4px;border-right:1px solid #151515;min-height:33px}.transcript-summary div:last-child{border:0}.transcript-footer{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-top:8px;font-size:9px;line-height:1.1}.transcript-footer p{margin:0}.transcript-footer div{text-align:center;font-size:11px}.transcript-footer span{display:block;margin-top:38px;font-size:9px;font-weight:normal}@media(max-width:768px){.transcript-paper{padding:12px 8px;overflow-x:auto}.transcript-header{min-width:680px}.transcript-info,.transcript-table,.transcript-summary{min-width:680px}}@media print{@page{size:A4 portrait;margin:6mm}body{background:#fff!important}.navbar,.no-print{display:none!important}main.container{max-width:none!important;padding:0!important}.transcript-paper{width:100%;max-width:none;min-height:0;margin:0;padding:0;box-shadow:none;font-size:10px}.semester-block{break-inside:avoid}.transcript-header{min-height:48px}.transcript-table{font-size:8.5px}.transcript-table th,.transcript-table td{padding:1.5px 2px}.element-name small{font-size:7.5px}.transcript-footer{margin-top:5px}}
</style>
<?php page_foot();


