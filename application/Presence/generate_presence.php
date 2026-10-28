<?php
require_once app_path('bootstrap.php');
require_permission('documents.issue');
require_once app_path('support/certificate_export.php');
require_once root_path('vendor/autoload.php');

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
    if (is_string($xml)) {
        $xml = institution_presence_signature_xml($xml);
    }
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

            // Other bilingual lines are Arabic-then-value in an LTR, right-aligned
            // paragraph. Keep that visual (Arabic left, INS005/2026 right) with
            // two runs so Latin letters cannot jump to the left.
            if (str_contains($text, '${inscription}')) {
                if (!preg_match('/<w:p\b[^>]*>/', $paragraph, $openMatch)) {
                    return $paragraph;
                }
                $pPr = '<w:pPr><w:ind w:left="360"/><w:jc w:val="right"/></w:pPr>';
                if (preg_match('/<w:pPr\b[^>]*>.*?<\/w:pPr>/s', $paragraph, $pPrMatch)) {
                    $pPr = preg_replace('/<w:bidi\\b[^\\/]*\\/>/', '', $pPrMatch[0]) ?? $pPrMatch[0];
                }
                $arabicPr = '<w:rPr><w:b/><w:bCs/><w:sz w:val="24"/><w:szCs w:val="24"/><w:rtl/></w:rPr>';
                $latinPr = '<w:rPr><w:b/><w:bCs/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr>';
                return $openMatch[0]
                    . $pPr
                    . '<w:r>' . $arabicPr . '<w:t xml:space="preserve">تحت رقم : </w:t></w:r>'
                    . '<w:r>' . $latinPr . '<w:t xml:space="preserve">${inscription}</w:t></w:r>'
                    . '</w:p>';
            }

            $replacement = match (true) {
                str_contains($text, '${prenom_ar}') => 'الاسم : ${prenom_ar}',
                str_contains($text, '${nom_ar}') => 'اللقب : ${nom_ar}',
                str_contains($text, '${lieu_naissance_ar}') => 'المولود في : ${date_naissance} بـ ${lieu_naissance_ar}',
                str_contains($text, '${cin}') => 'صاحب بطاقة تعريف عدد : ${cin}',
                str_contains($text, 'مرسم') => 'مرسم ب${annee_ar} من ${diplome_ar}',
                str_contains($text, '${specialite_ar}') => 'الاختصاص : ${specialite_ar}',
                str_contains($text, '${date_du_jour}') => '${lieu_emission}',
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

$templatePath = root_path('templates/template_presence_2.docx');
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
    $value = (string) $value;
    if ($value === '') {
        return '';
    }
    return "\u{202A}{$value}\u{202C}";
};
$rightToLeft = static function (?string $value): string {
    return (string) $value;
};

$templateProcessor->setValue('annee_univ', $currentYear . '/' . ($currentYear + 1));
$templateProcessor->setValue('cin', $student['cin_passeport'] ?? '');
$templateProcessor->setValue('date_du_jour', date('Y/m/d'));
$templateProcessor->setValue('date_naissance', $dateNaissance);
$templateProcessor->setValue('inscription', $leftToRight($student['num_inscription'] ?? ''));
$templateProcessor->setValue('lieu_naissance_ar', $rightToLeft($student['lieu_naissance_ar'] ?? ''));
$templateProcessor->setValue('nom_ar', $rightToLeft($student['nom_ar'] ?? ''));
$templateProcessor->setValue('prenom_ar', $rightToLeft($student['prenom_ar'] ?? ''));
$templateProcessor->setValue('specialite_ar', $rightToLeft($student['nom_spec_ar'] ?? $student['nom_spec'] ?? ''));
$templateProcessor->setValue('annee_ar', study_level_label_ar((string) ($student['niveau'] ?? '1')));
$templateProcessor->setValue('diplome_ar', institution_award_title_ar());
$templateProcessor->setValue('lieu_emission', institution_place_ar((string) date('Y/m/d')));
$templateProcessor->setValue('diplome', institution_award_title());
$templateProcessor->setValue('universite', institution_name_inline());
institution_apply_signatories($templateProcessor);

$fileBase = 'Attestation_Presence_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $student['cin_passeport']);
$tempFile = tempnam(sys_get_temp_dir(), 'PHPWord');
if ($tempFile === false) {
    http_response_code(500);
    exit('Erreur : impossible de créer le fichier temporaire.');
}
$docxPath = $tempFile . '.docx';
unlink($tempFile);

try {
    $templateProcessor->saveAs($docxPath);
    $preview = ($_GET['preview'] ?? '') === '1';
    $asPdf = !$preview && certificate_wants_pdf();
    certificate_send_generated_document($docxPath, $fileBase, $asPdf, $preview);
} catch (Throwable $exception) {
    http_response_code(500);
    exit('Erreur : ' . $exception->getMessage());
} finally {
    if (is_file($docxPath)) {
        unlink($docxPath);
    }
    unlink($preparedTemplatePath);
}

