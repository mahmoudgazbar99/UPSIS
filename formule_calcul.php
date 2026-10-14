<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_admin();

$error = '';
$message = '';
$moyenne = institution_active_formula('formula_moyenne', FORMULA_MOYENNE_DEFAULT);
$generale = institution_active_formula('formula_generale', FORMULA_GENERALE_DEFAULT);
$credit = institution_active_formula('formula_credit', FORMULA_CREDIT_DEFAULT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ((string) ($_POST['action'] ?? '') === 'reset') {
        institution_setting_put('formula_moyenne', '');
        institution_setting_put('formula_generale', '');
        institution_setting_put('formula_credit', '');
        $moyenne = FORMULA_MOYENNE_DEFAULT;
        $generale = FORMULA_GENERALE_DEFAULT;
        $credit = FORMULA_CREDIT_DEFAULT;
        $message = 'Les formules d’origine sont rétablies.';
    } else {
        $moyenne = trim((string) ($_POST['formula_moyenne'] ?? ''));
        $generale = trim((string) ($_POST['formula_generale'] ?? ''));
        $credit = trim((string) ($_POST['formula_credit'] ?? ''));
        try {
            formula_evaluate($moyenne, institution_mark_variables([
                'note_cours' => 16, 'note_td' => 14, 'note_tp' => null, 'note_rattrapage' => null,
                'note' => null, 'volume_tp' => 0, 'coeff' => 2, 'credit' => 5,
            ]));
            formula_evaluate($moyenne, institution_mark_variables([
                'note_cours' => 12, 'note_td' => 15, 'note_tp' => 13, 'note_rattrapage' => 11,
                'note' => null, 'volume_tp' => 10, 'coeff' => 2, 'credit' => 5,
            ]));
            formula_evaluate($generale, [
                'somme_ponderee' => 32, 'somme_coeff' => 2, 'somme_credits' => 5,
                'somme_moyennes' => 16, 'nombre' => 1,
            ]);
            formula_evaluate($credit, ['moyenne' => 9.5, 'credit' => 5, 'coeff' => 2]);
            institution_setting_put('formula_moyenne', $moyenne === FORMULA_MOYENNE_DEFAULT ? '' : $moyenne);
            institution_setting_put('formula_generale', $generale === FORMULA_GENERALE_DEFAULT ? '' : $generale);
            institution_setting_put('formula_credit', $credit === FORMULA_CREDIT_DEFAULT ? '' : $credit);
            $message = 'Les formules sont enregistrées. Le relevé de notes les utilise automatiquement.';
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            $error = 'L’enregistrement a échoué.';
        }
    }
}

$sample = institution_subject_average([
    'note_cours' => 16, 'note_td' => 14, 'note_tp' => null, 'note_rattrapage' => null,
    'note' => null, 'volume_tp' => 0, 'coeff' => 2, 'credit' => 5,
]);
$sampleCredit = $sample === null ? null : institution_acquired_credit($sample, 5);
$sampleGeneral = institution_general_average(($sample ?? 0) * 2, 2, 5, 1, $sample ?? 0);

page_head('Formule de calcul', 'grades');
?>
<div class="mb-4">
  <p class="eyebrow">Scolarité</p>
  <h1 class="display-6 fw-bold mb-1">Formule de calcul</h1>
  <p class="text-secondary mb-0">Chaque université définit sa moyenne, sa moyenne générale et ses crédits. Le relevé de notes applique ces formules.</p>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card app-card mb-4">
  <div class="card-body p-4">
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="col-12">
        <label class="form-label" for="formula_moyenne">Moyenne d’une matière</label>
        <textarea class="form-control font-monospace" id="formula_moyenne" name="formula_moyenne" rows="3" required><?= e($moyenne) ?></textarea>
        <div class="form-text">Variables : cc, examen, tp, rattrapage, note, coeff, credit, volume_tp, has_cc, has_examen, has_tp, has_rattrapage, has_note.</div>
      </div>
      <div class="col-12">
        <label class="form-label" for="formula_generale">Moyenne générale</label>
        <textarea class="form-control font-monospace" id="formula_generale" name="formula_generale" rows="2" required><?= e($generale) ?></textarea>
        <div class="form-text">Variables : somme_ponderee, somme_coeff, somme_credits, somme_moyennes, nombre.</div>
      </div>
      <div class="col-12">
        <label class="form-label" for="formula_credit">Crédit acquis</label>
        <textarea class="form-control font-monospace" id="formula_credit" name="formula_credit" rows="2" required><?= e($credit) ?></textarea>
        <div class="form-text">Variables : moyenne, credit. Opérateurs : + - * / ( ) ? : &amp;&amp; || ! == != &gt; &lt; &gt;= &lt;=. Fonctions : round, min, max, if.</div>
      </div>
      <div class="col-12 d-flex flex-wrap gap-2">
        <button class="btn btn-primary" type="submit">Enregistrer</button>
        <button class="btn btn-outline-secondary" type="submit" name="action" value="reset">Formule d’origine</button>
      </div>
    </form>
  </div>
</div>
<div class="card app-card">
  <div class="card-body p-4">
    <h2 class="h6">Exemple avec la formule active</h2>
    <p class="mb-0 text-secondary">Contrôle 16, examen 14, coefficient 2, crédit 5, sans TP ni rattrapage.</p>
    <p class="mb-0 mt-2"><strong>Moyenne :</strong> <?= $sample === null ? '—' : e(number_format($sample, 2, ',', '')) ?></p>
    <p class="mb-0"><strong>Moyenne générale :</strong> <?= $sampleGeneral === null ? '—' : e(number_format($sampleGeneral, 2, ',', '')) ?></p>
    <p class="mb-0"><strong>Crédit acquis :</strong> <?= $sampleCredit === null ? '—' : e(number_format($sampleCredit, 2, ',', '')) ?></p>
  </div>
</div>
<?php page_foot();
