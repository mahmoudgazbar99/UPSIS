<?php
require_once __DIR__ . '/config.php';
require_admin();
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

function preparePresenceTemplate(string $sourcePath): string
{
    $preparedPath = tempnam(sys_get_temp_dir(), 'presence-template');
    if ($preparedPath === false) {
        throw new RuntimeException('Impossible de créer le modèle temporaire.');
    }

    if (!copy($sourcePath, $preparedPath)) {
        unlink($preparedPath);
        throw new RuntimeException('Impossible de préparer le modèle de présence.');
    }

    $zip = new ZipArchive();
    if ($zip->open($preparedPath) !== true) {
        unlink($preparedPath);
        throw new RuntimeException('Impossible d’ouvrir le modèle de présence.');
    }

    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        $zip->close();
        unlink($preparedPath);
        throw new RuntimeException('Le contenu du document de présence est introuvable.');
    }

    $xml = preg_replace_callback(
        '/<w:p\b[^>]*>.*?<\/w:p>/s',
        static function (array $match): string {
            $paragraph = $match[0];
            preg_match_all('/<w:t\b[^>]*>(.*?)<\/w:t>/s', $paragraph, $textMatches);
            $text = html_entity_decode(implode('', $textMatches[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');

            $replacement = match (true) {
                str_contains($text, '${prenom_ar}') => 'الاسم : ${prenom_ar}',
                str_contains($text, '${nom_ar}') => 'اللقب : ${nom_ar}',
                str_contains($text, '${lieu_naissance_ar}') => 'المولود في : ${date_naissance} بـ ${lieu_naissance_ar}',
                str_contains($text, '${cin}') => 'صاحب بطاقة تعريف عدد : ${cin}',
                str_contains($text, 'مرسم') => 'مرسم بالسنة الأولى من الإجازة في علوم التمريض',
                str_contains($text, '${specialite_ar}') => 'الاختصاص : ${specialite_ar}',
                str_contains($text, '${inscription}') => 'تحت رقم : ${inscription}',
                str_contains($text, '${date_du_jour}') => 'قابس في، ${date_du_jour}',
                default => null,
            };
            if ($replacement === null || count($textMatches[0]) === 0) {
                return $paragraph;
            }

            $escapedReplacement = htmlspecialchars($replacement, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $textIndex = 0;
            return preg_replace_callback(
                '/<w:t\b([^>]*)>.*?<\/w:t>/s',
                static function (array $textMatch) use (&$textIndex, $escapedReplacement): string {
                    $value = $textIndex++ === 0 ? $escapedReplacement : '';
                    return '<w:t' . $textMatch[1] . '>' . $value . '</w:t>';
                },
                $paragraph
            );
        },
        $xml
    );

    if ($xml === null) {
        $zip->close();
        unlink($preparedPath);
        throw new RuntimeException('Impossible de corriger le texte arabe du modèle.');
    }

    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
    return $preparedPath;
}

$cin = trim($_GET['cin'] ?? '');
if ($cin === '') {
    http_response_code(400);
    exit('Erreur : CIN non fourni.');
}

$stmt = db()->prepare('
    SELECT e.*, s.nom_spec, s.nom_spec_ar
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

$templatePath = __DIR__ . '/templates/template_presence_2.docx';
if (!is_file($templatePath)) {
    http_response_code(500);
    exit('Erreur : Le modèle Word de présence est introuvable.');
}

$dateNaissance = '';
if (!empty($student['date_naissance'])) {
    $timestamp = strtotime($student['date_naissance']);
    $dateNaissance = $timestamp ? date('Y/m/d', $timestamp) : (string) $student['date_naissance'];
}

$currentYear = (int) date('Y');
$preparedTemplatePath = preparePresenceTemplate($templatePath);
$templateProcessor = new TemplateProcessor($preparedTemplatePath);
$leftToRight = static function (?string $value): string {
    return (string) $value;
};
$rightToLeft = static function (?string $value): string {
    return (string) $value;
};

$templateProcessor->setValue('annee_univ', $leftToRight($currentYear . '/' . ($currentYear + 1)));
$templateProcessor->setValue('cin', $leftToRight($student['cin_passeport'] ?? ''));
$templateProcessor->setValue('date_du_jour', $leftToRight(date('Y/m/d')));
$templateProcessor->setValue('date_naissance', $leftToRight($dateNaissance));
$templateProcessor->setValue('inscription', $leftToRight($student['num_inscription'] ?? ''));
$templateProcessor->setValue('lieu_naissance_ar', $rightToLeft($student['lieu_naissance_ar'] ?? ''));
$templateProcessor->setValue('nom_ar', $rightToLeft($student['nom_ar'] ?? ''));
$templateProcessor->setValue('prenom_ar', $rightToLeft($student['prenom_ar'] ?? ''));
$templateProcessor->setValue('specialite_ar', $rightToLeft($student['nom_spec_ar'] ?? $student['nom_spec'] ?? ''));

$fileName = 'Attestation_Presence_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $student['cin_passeport']) . '.docx';
$tempFile = tempnam(sys_get_temp_dir(), 'PHPWord');
if ($tempFile === false) {
    http_response_code(500);
    exit('Erreur : impossible de créer le fichier temporaire.');
}

try {
    $templateProcessor->saveAs($tempFile);
    $preview = ($_GET['preview'] ?? '') === '1';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($tempFile));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($tempFile);
} finally {
    unlink($tempFile);
    unlink($preparedTemplatePath);
}

