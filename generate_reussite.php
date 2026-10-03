<?php
require_once __DIR__ . '/config.php';
require_admin();
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

$cin = trim($_GET['cin'] ?? '');
if ($cin === '') {
    http_response_code(400);
    exit('Erreur : CIN non fourni.');
}

$stmt = db()->prepare('
    SELECT e.*, s.nom_spec
    FROM etudiant e
    LEFT JOIN specialite s ON s.id_spec = e.id_spec
    WHERE e.cin_passeport = ?
');
$stmt->execute([$cin]);
$student = $stmt->fetch();

if (!$student) {
    http_response_code(404);
    exit('Erreur : Étudiant introuvable dans la base de données.');
}

$phpWord = new PhpWord();
$phpWord->setDefaultFontName('Arial');
$phpWord->setDefaultFontSize(12);
$section = $phpWord->addSection([
    'paperSize' => 'A4',
    'marginTop' => 900,
    'marginBottom' => 900,
    'marginLeft' => 1100,
    'marginRight' => 1100,
]);

$section->addImage(__DIR__ . '/images/no_bg_logo.png', [
    'width' => 460,
    'height' => 85,
    'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER,
]);
$section->addText('ATTESTATION DE RÉUSSITE', [
    'bold' => true,
    'size' => 18,
    'underline' => 'single',
], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spaceAfter' => 700]);

$section->addText(
    'Le Secrétaire général de l\'Université privée des sciences infirmières et de la santé de Gabès atteste par la présente que :',
    ['size' => 12],
    ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::BOTH, 'spaceAfter' => 250]
);

$table = $section->addTable([
    'borderSize' => 6,
    'borderColor' => 'B8C4D4',
    'cellMargin' => 100,
]);
$rows = [
    ['Nom et prénom', $student['nom'] . ' ' . $student['prenom']],
    ['CIN / Passeport', $student['cin_passeport']],
    ['Date et lieu de naissance', date('d/m/Y', strtotime($student['date_naissance'])) . ' à ' . $student['lieu_naissance']],
    ['Numéro d’inscription', $student['num_inscription']],
];
foreach ($rows as [$label, $value]) {
    $cells = $table->addRow()->getCells();
    $cells[0]->addText($label, ['bold' => true]);
    $cells[1]->addText((string) $value);
}

$section->addText(
    'a satisfait aux exigences de l’année universitaire et a été déclaré(e) ADMIS(E) dans la spécialité :',
    ['size' => 12],
    ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::BOTH, 'spaceBefore' => 450]
);
$section->addText(
    $student['nom_spec'] . ' — ' . level_label((string) $student['niveau']),
    ['bold' => true, 'size' => 14],
    ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spaceBefore' => 250]
);
$section->addText(
    'La présente attestation est délivrée à l’intéressé(e) pour servir et valoir ce que de droit.',
    ['size' => 12],
    ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::BOTH, 'spaceBefore' => 450]
);

$footer = $section->addFooter();
$footer->addText('Référence : ' . $student['num_inscription'] . ' — Fait le ' . date('d/m/Y'), [
    'size' => 10,
], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

$fileName = 'Attestation_Reussite_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $student['cin_passeport']) . '.docx';
$tempFile = tempnam(sys_get_temp_dir(), 'PHPWord');
if ($tempFile === false) {
    http_response_code(500);
    exit('Erreur : impossible de créer le fichier temporaire.');
}

try {
    $writer = IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save($tempFile);
    $preview = ($_GET['preview'] ?? '') === '1';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($tempFile));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($tempFile);
} finally {
    unlink($tempFile);
}

