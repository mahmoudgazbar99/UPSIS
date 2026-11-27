<?php
declare(strict_types=1);

require_once app_path('bootstrap.php');
require_once app_path('support/public_theme.php');
admissions_ensure();

$forms = admissions_printable_forms();
$type = trim((string) ($_GET['type'] ?? ''));
if ($type === '' || !isset($forms[$type])) {
    http_response_code(404);
    echo 'Formulaire inconnu.';
    exit;
}

$appId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$candidate = null;
$app = null;
if ($appId > 0 && !empty($_SESSION['candidate_id'])) {
    require_candidate();
    $app = admissions_find_application($appId);
    if (!$app || (int) $app['id_candidate'] !== current_candidate_id()) {
        http_response_code(403);
        echo 'Accès refusé.';
        exit;
    }
    $candidate = [
        'nom' => (string) $app['nom'],
        'prenom' => (string) $app['prenom'],
        'cin' => (string) $app['cin_passeport'],
        'email' => (string) $app['email'],
        'tel' => (string) ($app['num_tel'] ?? ''),
        'naissance' => (string) ($app['date_naissance'] ?? ''),
        'ref' => (string) $app['application_number'],
        'annee' => (string) ($app['academic_year_label'] ?? ''),
        'spec' => (string) ($app['nom_spec'] ?? ''),
    ];
} elseif ($appId > 0 && (is_admin() || is_scolarite() || role_can('admissions.view'))) {
    $app = admissions_find_application($appId);
    if ($app) {
        $candidate = [
            'nom' => (string) $app['nom'],
            'prenom' => (string) $app['prenom'],
            'cin' => (string) $app['cin_passeport'],
            'email' => (string) $app['email'],
            'tel' => (string) ($app['num_tel'] ?? ''),
            'naissance' => (string) ($app['date_naissance'] ?? ''),
            'ref' => (string) $app['application_number'],
            'annee' => (string) ($app['academic_year_label'] ?? ''),
            'spec' => (string) ($app['nom_spec'] ?? ''),
        ];
    }
}

$meta = $forms[$type];
$school = institution_name_inline();
$today = date('d/m/Y');
$v = static function (string $key) use ($candidate): string {
    if (!$candidate) {
        return '……………………………………';
    }
    $val = trim((string) ($candidate[$key] ?? ''));
    return $val !== '' ? $val : '……………………………………';
};
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($meta['title']) ?> | <?= e($school) ?></title>
  <style>
    :root { color-scheme: light; }
    body { font-family: "Segoe UI", Tahoma, sans-serif; margin: 0; background: #f4f6f8; color: #122; }
    .sheet { max-width: 800px; margin: 1.5rem auto; background: #fff; padding: 2rem 2.25rem; box-shadow: 0 8px 30px rgba(0,0,0,.08); }
    h1 { font-size: 1.25rem; margin: 0 0 .35rem; }
    .kicker { text-transform: uppercase; letter-spacing: .08em; font-size: .72rem; color: #567; margin: 0; }
    .meta { font-size: .9rem; color: #456; margin: .75rem 0 1.25rem; }
    .hint { border-left: 3px solid #0b6; padding: .6rem .9rem; background: #f3fbf7; margin-bottom: 1.25rem; font-size: .92rem; }
    .admin { border-left-color: #c60; background: #fff8f0; }
    label { display: block; font-size: .85rem; color: #456; margin-top: .85rem; }
    .line { border-bottom: 1px solid #ccd; min-height: 1.4rem; padding: .2rem 0; font-weight: 600; }
    .box { border: 1px solid #ccd; border-radius: 8px; padding: .9rem 1rem; margin-top: 1rem; }
    .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 2rem; }
    .actions { max-width: 800px; margin: 1rem auto 0; display: flex; gap: .5rem; flex-wrap: wrap; }
    .actions a, .actions button { appearance: none; border: 0; background: #0a5; color: #fff; padding: .55rem 1rem; border-radius: 999px; text-decoration: none; cursor: pointer; font: inherit; }
    .actions a.ghost { background: #334; }
    @media print {
      body { background: #fff; }
      .actions { display: none; }
      .sheet { box-shadow: none; margin: 0; max-width: none; }
    }
  </style>
</head>
<body>
  <div class="actions">
    <button type="button" onclick="window.print()">Imprimer / PDF</button>
    <?php if ($appId > 0): ?>
      <a class="ghost" href="espace_candidat.php?id=<?= (int) $appId ?>">Retour espace candidat</a>
    <?php endif; ?>
  </div>
  <article class="sheet">
    <p class="kicker"><?= e($school) ?></p>
    <h1><?= e($meta['title']) ?></h1>
    <p class="meta">Année universitaire <?= e($v('annee')) ?> · Réf. <?= e($v('ref')) ?></p>
    <div class="hint <?= $meta['channel'] === 'admin' || $meta['channel'] === 'both' ? 'admin' : '' ?>">
      <?= e($meta['hint']) ?>
      <?php if ($meta['channel'] === 'both' || $meta['channel'] === 'admin'): ?>
        <br><strong>Hors application :</strong> démarches physiques / légalisation / dépôt à la scolarité si demandé.
      <?php endif; ?>
    </div>

    <div class="box">
      <label>Nom</label><div class="line"><?= e($v('nom')) ?></div>
      <label>Prénom</label><div class="line"><?= e($v('prenom')) ?></div>
      <label>CIN / passeport</label><div class="line"><?= e($v('cin')) ?></div>
      <label>Date de naissance</label><div class="line"><?= e($v('naissance')) ?></div>
      <label>Téléphone</label><div class="line"><?= e($v('tel')) ?></div>
      <label>E-mail</label><div class="line"><?= e($v('email')) ?></div>
      <label>Spécialité demandée</label><div class="line"><?= e($v('spec')) ?></div>
    </div>

    <?php if ($type === 'test_request'): ?>
      <div class="box">
        <p>Je soussigné(e) demande à passer le <strong>test d’admission</strong> devant la commission (3 membres), après paiement du droit de <strong>150 dinars</strong>.</p>
        <p>Le test vise à vérifier la préparation psychologique. La réussite conditionne le dépôt du dossier d’inscription finale.</p>
        <p>Quittance n° : ………………………… · Date de paiement : …………………………</p>
      </div>
    <?php elseif ($type === 'declaration'): ?>
      <div class="box">
        <p>Je déclare sur l’honneur n’être inscrit(e) dans aucun autre établissement universitaire public ou privé en Tunisie ou à l’étranger pour l’année universitaire en cours.</p>
      </div>
    <?php elseif ($type === 'reglement'): ?>
      <div class="box">
        <p>Je m’engage à respecter le règlement intérieur de <?= e($school) ?> pendant toute la durée de mes études.</p>
        <p><strong>Signature à légaliser</strong> auprès de l’autorité compétente, puis dépôt / scan sur l’espace candidat.</p>
      </div>
    <?php elseif ($type === 'dette'): ?>
      <div class="box">
        <p>Reconnaissance de dette — paiement des frais de formation en <strong>trois tranches</strong>.</p>
        <p>Montant total convenu : ………………………… DT</p>
        <p>Échéances : 1) ……… / ……… / ……… · 2) ……… / ……… / ……… · 3) ……… / ……… / ………</p>
        <p><strong>Signature du étudiant ou du tuteur légalisée.</strong></p>
      </div>
    <?php else: ?>
      <div class="box">
        <p>Formulaire d’inscription — à compléter, signer, puis téléverser sur l’espace candidat (ou déposer à la scolarité).</p>
        <label>Adresse</label><div class="line">……………………………………</div>
        <label>Niveau / filière</label><div class="line"><?= e($v('spec')) ?></div>
      </div>
    <?php endif; ?>

    <div class="sign">
      <div>
        <label>Fait à</label><div class="line">……………………</div>
        <label>Le</label><div class="line"><?= e($today) ?></div>
      </div>
      <div>
        <label>Signature<?= in_array($type, ['reglement', 'dette'], true) ? ' (légalisée)' : '' ?></label>
        <div class="line" style="min-height:4rem"></div>
      </div>
    </div>
  </article>
</body>
</html>
