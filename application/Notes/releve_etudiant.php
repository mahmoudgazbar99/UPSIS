<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('layout.php');

if (!is_student()) {
    header('Location: connexion_etudiant.php');
    exit;
}

$studentId = (int) ($_SESSION['id_etudiant'] ?? 0);
$year = result_active_year();
$published = result_published_for_student($studentId, $year);
if ($published === null) {
    http_response_code(404);
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
    exit('Dossier introuvable.');
}

$marks = $published['marks'];
$marksBySemester = [];
foreach ($marks as $mark) {
    $marksBySemester[(string) ($mark['semestre'] ?? '')][] = $mark;
}
$generalAverage = $published['moyenne'];
$decision = result_decision_label($published['decision']);
$academicYear = str_contains($year, ' - ') ? $year : str_replace('-', ' - ', $year);

function student_transcript_number(float $number): string
{
    return number_format($number, 2, ',', '');
}

function student_transcript_mention(?float $average): string
{
    if ($average === null) {
        return '—';
    }
    if ($average >= 16) {
        return 'Très bien';
    }
    if ($average >= 14) {
        return 'Bien';
    }
    if ($average >= 12) {
        return 'Assez bien';
    }
    if ($average >= 10) {
        return 'Passable';
    }
    return 'Ajourné';
}

page_head('Mon relevé de notes', 'transcript');
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
  <div>
    <p class="eyebrow">Espace étudiant</p>
    <h1 class="display-6 fw-bold mb-0">Relevé de notes</h1>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-light" href="dashboard.php?panel=notes">Retour</a>
    <button class="btn btn-primary" type="button" onclick="window.print()">Télécharger / Imprimer</button>
  </div>
</div>

<?php
$weightedTotal = 0.0;
$coeffTotal = 0.0;
$creditsTotal = 0.0;
$creditsAcquired = 0.0;
$averageSum = 0.0;
$averageCount = 0;
foreach ($marksBySemester as $semesterMarks) {
    foreach ($semesterMarks as $mark) {
        $finalNote = calculated_note($mark) ?? 0.0;
        $weightedTotal += $finalNote * (float) ($mark['coeff'] ?? 0);
        $coeffTotal += (float) ($mark['coeff'] ?? 0);
        $creditsTotal += (float) ($mark['credit'] ?? 0);
        $creditsAcquired += institution_acquired_credit($finalNote, (float) ($mark['credit'] ?? 0));
        $averageSum += $finalNote;
        $averageCount++;
    }
}
if ($generalAverage === null) {
    $generalAverage = institution_general_average($weightedTotal, $coeffTotal, $creditsTotal, $averageCount, $averageSum);
}
$situation = match ($published['decision']) {
    'admis', 'admis_par_rachat' => 'Admis(e)',
    'refusee' => 'Refusé(e)',
    default => $decision,
};
?>
<article class="transcript-paper">
  <header class="transcript-header">
    <section><img src="<?= e(institution_logo_src()) ?>" alt="Logo institutionnel" class="transcript-logo"><strong>République Tunisienne</strong><span>Ministère de l'Enseignement Supérieur<br>et de la Recherche Scientifique</span></section>
    <section class="transcript-title"><h1>RELEVÉ DE NOTES</h1><span><?= e($academicYear) ?></span></section>
    <section><?php foreach (institution_name_lines() as $index => $institutionLine): ?><?= $index === 0 ? '<strong>' . e($institutionLine) . '</strong>' : '<span>' . e($institutionLine) . '</span>' ?><?php endforeach; ?></section>
  </header>
  <section class="transcript-info">
    <div>
      <p><b>Nom et Prénom :</b> <?= e($student['nom'] . ' ' . $student['prenom']) ?></p>
      <p><b>Né(e) le :</b> <?= e(date('d/m/Y', strtotime((string) $student['date_naissance']))) ?> <b>à :</b> <?= e((string) $student['lieu_naissance']) ?></p>
      <p><b>Diplôme / Spécialité :</b> <?= e((string) $student['nom_spec']) ?></p>
    </div>
    <div>
      <p><b>Situation :</b> <?= e($situation) ?></p>
      <p><b>N° de CIN :</b> <?= e((string) $student['cin_passeport']) ?></p>
      <p><b>N° d'Inscription :</b> <?= e((string) $student['num_inscription']) ?></p>
      <p><b>Niveau d'étude :</b> <?= e(level_label((string) $student['niveau'])) ?></p>
    </div>
  </section>

  <?php if ($marksBySemester === []): ?>
    <div class="transcript-empty">Aucune note dans le relevé publié.</div>
  <?php endif; ?>
  <?php foreach ($marksBySemester as $semester => $semesterMarks):
      $semesterWeighted = 0.0;
      $semesterCoefficient = 0.0;
      $semesterCredits = 0.0;
      $semesterSum = 0.0;
      $semesterCount = 0;
      foreach ($semesterMarks as $semesterMark) {
          $semesterNote = calculated_note($semesterMark) ?? 0.0;
          $semesterWeighted += $semesterNote * (float) ($semesterMark['coeff'] ?? 0);
          $semesterCoefficient += (float) ($semesterMark['coeff'] ?? 0);
          $semesterCredits += (float) ($semesterMark['credit'] ?? 0);
          $semesterSum += $semesterNote;
          $semesterCount++;
      }
      $semesterAverage = institution_general_average($semesterWeighted, $semesterCoefficient, $semesterCredits, $semesterCount, $semesterSum);
  ?>
  <section class="semester-block">
    <h2>Semestre <?= e((string) $semester) ?><span class="semester-average">Moyenne : <?= $semesterAverage === null ? '—' : student_transcript_number($semesterAverage) ?></span></h2>
    <table class="transcript-table">
      <thead><tr><th class="ue-col">Matière</th><th>Coef</th><th>Cr</th><th>Moy.</th></tr></thead>
      <tbody>
        <?php foreach ($semesterMarks as $element): ?>
          <tr>
            <td class="element-name"><span><?= e((string) ($element['description_ecue'] ?? '')) ?></span><small><?= e((string) ($element['code_ecue'] ?? '')) ?></small></td>
            <td><?= student_transcript_number((float) ($element['coeff'] ?? 0)) ?></td>
            <td><?= student_transcript_number((float) ($element['credit'] ?? 0)) ?></td>
            <td><?= student_transcript_number(calculated_note($element) ?? 0.0) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endforeach; ?>

  <section class="transcript-summary">
    <div><b>Moyenne générale :</b> <?= $generalAverage === null ? '—' : student_transcript_number($generalAverage) ?></div>
    <div><b>Crédits totaux :</b> <?= student_transcript_number($creditsTotal) ?></div>
    <div><b>Crédits acquis :</b> <?= student_transcript_number($creditsAcquired) ?></div>
    <div><b>Mention :</b> <?= e(student_transcript_mention($generalAverage)) ?></div>
    <div><b>Décision :</b> <?= e($decision) ?></div>
  </section>
</article>

<style>
.transcript-paper{max-width:210mm;min-height:297mm;margin:0 auto 2rem;background:#fff;color:#111;padding:8mm 9mm;font-family:"Times New Roman",serif;font-size:11px;box-shadow:0 18px 45px #0008}.transcript-header{display:grid;grid-template-columns:1.25fr 1.25fr 1.25fr;border:1.5px solid #151515;text-align:center;min-height:53px}.transcript-header section{display:flex;flex-direction:column;justify-content:center;align-items:center;padding:4px;border-right:1px solid #151515;line-height:1.13}.transcript-header section:last-child{border:0}.transcript-header strong{font-size:12px;text-transform:uppercase}.transcript-header span{font-weight:700;font-size:10px}.transcript-logo{width:46px;height:22px;object-fit:contain;margin-bottom:1px}.transcript-title h1{font-size:23px;line-height:1;margin:0 0 4px;font-weight:800}.transcript-title span{font-size:15px}.transcript-info{display:grid;grid-template-columns:2.15fr 1fr;border:1px solid #151515;border-top:0;padding:4px 6px;line-height:1.2}.transcript-info p{margin:0 0 3px;font-size:12px}.transcript-empty{border:1px solid #151515;border-top:0;text-align:center;padding:12px;font-style:italic}.semester-block h2{font-size:13px;border:1px solid #151515;border-bottom:0;margin:0;padding:3px 5px;background:#f0f0f0;display:flex;justify-content:space-between;align-items:center}.semester-average{font-size:11px;font-weight:700}.transcript-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:10px}.transcript-table th,.transcript-table td{border:1px solid #151515;padding:2px 3px;vertical-align:middle;text-align:center;line-height:1.05}.transcript-table th{background:#d9d9d9;font-weight:700}.transcript-table th.ue-col{width:55%;text-align:left}.transcript-table .element-name{text-align:left;padding-left:8px}.element-name span{display:block}.element-name small{display:block;font-size:8px;color:#444;margin-top:2px}.transcript-summary{display:grid;grid-template-columns:repeat(5,1fr);border:1px solid #151515;border-top:0}.transcript-summary div{padding:5px 4px;border-right:1px solid #151515;min-height:33px}.transcript-summary div:last-child{border:0}@media print{@page{size:A4 portrait;margin:6mm}body{background:#fff!important}.navbar,.no-print{display:none!important}main.container{max-width:none!important;padding:0!important}.transcript-paper{box-shadow:none;margin:0}}
</style>
<?php page_foot();
