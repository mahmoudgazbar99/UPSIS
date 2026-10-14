<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_admin();

$pdo = db();
$availableYears = $pdo->query('SELECT DISTINCT annee_scolaire FROM etudiant_archive ORDER BY annee_scolaire DESC')->fetchAll(PDO::FETCH_COLUMN);
$year = trim((string) ($_GET['year'] ?? ($availableYears[0] ?? '')));
$search = trim((string) ($_GET['search'] ?? ''));
$groupId = max(0, (int) ($_GET['group_id'] ?? 0));
$selected = array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['archive_ids'] ?? [])))));
$format = (string) ($_GET['format'] ?? '');
$exportAction = (string) ($_GET['export_action'] ?? '');
$embedded = ($_GET['embed'] ?? '') === '1';
$exportError = '';

if ($year !== '' && !preg_match('/^\d{4}-\d{4}$/', $year)) {
    http_response_code(400);
    exit('Année d’archive invalide.');
}

$isExporting = $format !== '' || $exportAction !== '';
$where = ['ea.annee_scolaire = :year'];
$params = ['year' => $year];
if ($groupId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM groupe_etudiant ge WHERE ge.id_etudiant = ea.id_etudiant AND ge.id_groupe = :group_id)';
    $params['group_id'] = $groupId;
}
// Export still limits to checked rows. Display keeps full year/group list
// and pins search hits to the top instead of leaving them buried.
if ($selected && $isExporting) {
    $placeholders = [];
    foreach ($selected as $index => $archiveId) {
        $key = 'archive_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $archiveId;
    }
    $where[] = 'ea.id_archive IN (' . implode(',', $placeholders) . ')';
} elseif ($search !== '' && $isExporting && !$selected) {
    $where[] = '(ea.cin_passeport = :sx1 OR ea.num_inscription = :sx2'
        . ' OR ea.cin_passeport LIKE :sl1 OR ea.num_inscription LIKE :sl2'
        . ' OR ea.nom_spec LIKE :sl3'
        . ' OR JSON_UNQUOTE(JSON_EXTRACT(ea.student_payload, "$.nom")) LIKE :sl4'
        . ' OR JSON_UNQUOTE(JSON_EXTRACT(ea.student_payload, "$.prenom")) LIKE :sl5)';
    $params['sx1'] = $search;
    $params['sx2'] = $search;
    $like = '%' . $search . '%';
    $params['sl1'] = $like;
    $params['sl2'] = $like;
    $params['sl3'] = $like;
    $params['sl4'] = $like;
    $params['sl5'] = $like;
}
$archiveSelect = 'SELECT ea.id_archive, ea.id_etudiant, ea.annee_scolaire, ea.niveau, ea.nom_spec,
            ea.num_inscription, ea.cin_passeport, ea.decision, ea.moyenne_generale,
            ea.finance_total_paye, ea.student_payload, ea.transcript_payload,
            9800 - ea.finance_total_paye AS remaining_balance
     FROM etudiant_archive ea';
$archiveQuery = $pdo->prepare(
    $archiveSelect . '
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY ea.num_inscription, ea.id_archive'
);
$archiveQuery->execute($params);
$archives = $archiveQuery->fetchAll();

// Search/selection pin must work even when the student is outside the active group filter.
if (!$isExporting && $search !== '') {
    $searchQuery = $pdo->prepare(
        $archiveSelect . '
         WHERE ea.annee_scolaire = :year
           AND (
                ea.cin_passeport = :sx1 OR ea.num_inscription = :sx2
                OR ea.cin_passeport LIKE :sl1 OR ea.num_inscription LIKE :sl2
                OR JSON_UNQUOTE(JSON_EXTRACT(ea.student_payload, "$.nom")) LIKE :sl3
                OR JSON_UNQUOTE(JSON_EXTRACT(ea.student_payload, "$.prenom")) LIKE :sl4
                OR CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ea.student_payload, "$.nom")), ""), " ", COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ea.student_payload, "$.prenom")), "")) LIKE :sl5
                OR CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ea.student_payload, "$.prenom")), ""), " ", COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ea.student_payload, "$.nom")), "")) LIKE :sl6
           )
         ORDER BY ea.num_inscription, ea.id_archive'
    );
    $like = '%' . $search . '%';
    $searchQuery->execute([
        'year' => $year,
        'sx1' => $search,
        'sx2' => $search,
        'sl1' => $like,
        'sl2' => $like,
        'sl3' => $like,
        'sl4' => $like,
        'sl5' => $like,
        'sl6' => $like,
    ]);
    $searchHits = $searchQuery->fetchAll();
    if ($searchHits) {
        $existingIds = [];
        foreach ($archives as $archive) {
            $existingIds[(int) $archive['id_archive']] = true;
        }
        foreach ($searchHits as $hit) {
            $hitId = (int) $hit['id_archive'];
            if (!isset($existingIds[$hitId])) {
                array_unshift($archives, $hit);
                $existingIds[$hitId] = true;
            }
            if (!in_array($hitId, $selected, true)) {
                $selected[] = $hitId;
            }
        }
    }
}
if (!$isExporting && $selected) {
    $missingIds = [];
    $presentIds = [];
    foreach ($archives as $archive) {
        $presentIds[(int) $archive['id_archive']] = true;
    }
    foreach ($selected as $archiveId) {
        if (!isset($presentIds[$archiveId])) {
            $missingIds[] = $archiveId;
        }
    }
    if ($missingIds) {
        $placeholders = [];
        $missingParams = [];
        foreach ($missingIds as $index => $archiveId) {
            $key = 'missing_' . $index;
            $placeholders[] = ':' . $key;
            $missingParams[$key] = $archiveId;
        }
        $missingQuery = $pdo->prepare(
            $archiveSelect . '
             WHERE ea.id_archive IN (' . implode(',', $placeholders) . ')'
        );
        $missingQuery->execute($missingParams);
        foreach ($missingQuery->fetchAll() as $missing) {
            array_unshift($archives, $missing);
        }
    }
}
$archiveMatchesSearch = static function (array $archive, string $needle): int {
    if ($needle === '') {
        return 2;
    }
    $payload = json_decode((string) $archive['student_payload'], true);
    $nom = is_array($payload) ? trim((string) ($payload['nom'] ?? '')) : '';
    $prenom = is_array($payload) ? trim((string) ($payload['prenom'] ?? '')) : '';
    $fullNomPrenom = trim($nom . ' ' . $prenom);
    $fullPrenomNom = trim($prenom . ' ' . $nom);
    $cin = (string) $archive['cin_passeport'];
    $inscription = (string) $archive['num_inscription'];
    if (strcasecmp($cin, $needle) === 0 || strcasecmp($inscription, $needle) === 0) {
        return 0;
    }
    $fields = [$cin, $inscription, $nom, $prenom, $fullNomPrenom, $fullPrenomNom];
    foreach ($fields as $field) {
        if ($field === '') {
            continue;
        }
        if (function_exists('mb_stripos') ? mb_stripos($field, $needle) !== false : stripos($field, $needle) !== false) {
            return 1;
        }
    }
    return 2;
};

$pinnedArchives = [];
$otherArchives = [];
if (!$isExporting && ($search !== '' || $selected)) {
    $selectedLookup = array_fill_keys($selected, true);
    foreach ($archives as $archive) {
        $archiveId = (int) $archive['id_archive'];
        $isPinned = isset($selectedLookup[$archiveId]) || $archiveMatchesSearch($archive, $search) < 2;
        if ($isPinned) {
            $pinnedArchives[] = $archive;
        } else {
            $otherArchives[] = $archive;
        }
    }
    $nameSort = static function (array $left, array $right): int {
        $leftPayload = json_decode((string) $left['student_payload'], true);
        $rightPayload = json_decode((string) $right['student_payload'], true);
        $leftName = is_array($leftPayload)
            ? trim((string) ($leftPayload['nom'] ?? '') . ' ' . (string) ($leftPayload['prenom'] ?? ''))
            : '';
        $rightName = is_array($rightPayload)
            ? trim((string) ($rightPayload['nom'] ?? '') . ' ' . (string) ($rightPayload['prenom'] ?? ''))
            : '';
        $byName = strcasecmp($leftName, $rightName);
        return $byName !== 0 ? $byName : ((int) $left['id_archive'] <=> (int) $right['id_archive']);
    };
    usort($pinnedArchives, static function (array $left, array $right) use ($search, $selectedLookup, $archiveMatchesSearch, $nameSort): int {
        $leftSelected = isset($selectedLookup[(int) $left['id_archive']]) ? 0 : 1;
        $rightSelected = isset($selectedLookup[(int) $right['id_archive']]) ? 0 : 1;
        if ($leftSelected !== $rightSelected) {
            return $leftSelected <=> $rightSelected;
        }
        $leftRank = $archiveMatchesSearch($left, $search);
        $rightRank = $archiveMatchesSearch($right, $search);
        if ($leftRank !== $rightRank) {
            return $leftRank <=> $rightRank;
        }
        return $nameSort($left, $right);
    });
    usort($otherArchives, $nameSort);
    $archives = array_merge($pinnedArchives, $otherArchives);
    if ($search !== '' && !$selected) {
        foreach ($pinnedArchives as $archive) {
            $selected[] = (int) $archive['id_archive'];
        }
        $selected = array_values(array_unique($selected));
    }
} else {
    $otherArchives = $archives;
}
$paymentHistories = [];
if ($archives) {
    $paymentHistoryQuery = $pdo->prepare(
        'SELECT sfa.id_etudiant, sfa.mode_paiement, sfa.montant_paye,
                sfa.payment_created_at, sfa.payment_updated_at
         FROM student_fees_archive sfa
         WHERE sfa.id_archive = ?
         ORDER BY sfa.payment_created_at, sfa.source_payment_id'
    );
    foreach ($archives as $archive) {
        $paymentHistoryQuery->execute([(int) $archive['id_archive']]);
        $paymentHistories[(int) $archive['id_archive']] = $paymentHistoryQuery->fetchAll();
    }
}

if ($exportAction !== '') {
    if (!in_array($exportAction, ['registration', 'transcript', 'payment_history'], true)) {
        $exportError = 'Type de document invalide.';
    } elseif (count($selected) !== 1 || count($archives) !== 1) {
        $exportError = 'Sélectionnez exactement un étudiant pour générer ce document.';
    } elseif ($exportAction === 'payment_history') {
        require_once __DIR__ . '/vendor/autoload.php';
        $archive = $archives[0];
        $archiveId = (int) $archive['id_archive'];
        $namePayload = json_decode((string) $archive['student_payload'], true);
        $studentName = is_array($namePayload)
            ? trim((string) ($namePayload['prenom'] ?? '') . ' ' . (string) ($namePayload['nom'] ?? ''))
            : '';
        $payments = $paymentHistories[$archiveId] ?? [];
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = '';
        foreach ($payments as $payment) {
            $paymentDate = (string) ($payment['payment_created_at'] ?? '');
            $updatedDate = (string) ($payment['payment_updated_at'] ?? '');
            $rows .= '<tr><td>' . $escape($paymentDate !== '' ? date('d/m/Y H:i', strtotime($paymentDate)) : '—')
                . '</td><td>' . $escape($payment['mode_paiement'] === 'virement' ? 'Virement' : 'Espèce')
                . '</td><td>' . $escape(number_format((float) $payment['montant_paye'], 2, ',', ' ')) . ' DT</td>'
                . '<td>' . $escape($updatedDate !== '' ? date('d/m/Y H:i', strtotime($updatedDate)) : '—') . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="empty">Aucun paiement archivé pour cet étudiant.</td></tr>';
        }
        $html = '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><style>
            @page{size:A4 portrait;margin:18mm}
            body{font-family:DejaVu Sans,sans-serif;color:#17212b;font-size:11pt}
            h1{text-align:center;font-size:18pt;margin:0 0 6mm}
            .institution{text-align:center;color:#465563;margin-bottom:10mm}
            .student{border:1px solid #9aa8b5;padding:4mm;margin-bottom:8mm;line-height:1.7}
            table{width:100%;border-collapse:collapse}
            th,td{border:1px solid #7f8c98;padding:3mm;text-align:left}
            th{background:#e8edf1}
            .empty{text-align:center;color:#52606d}
            .summary{margin-top:8mm;border:1px solid #9aa8b5;padding:4mm;font-weight:bold;line-height:1.8}
            .footer{margin-top:14mm;text-align:right}
        </style></head><body>
            <div class="institution">Université privée des sciences infirmières et de santé de Gabès</div>
            <h1>HISTORIQUE DES PAIEMENTS</h1>
            <div class="student"><strong>Étudiant :</strong> ' . $escape($studentName) . '<br>
                <strong>CIN / Passeport :</strong> ' . $escape((string) $archive['cin_passeport']) . '<br>
                <strong>N° d’inscription :</strong> ' . $escape((string) $archive['num_inscription']) . '<br>
                <strong>Année archivée :</strong> ' . $escape((string) $archive['annee_scolaire']) . '</div>
            <table><thead><tr><th>Date du paiement</th><th>Mode</th><th>Montant</th><th>Date de modification</th></tr></thead>
                <tbody>' . $rows . '</tbody></table>
            <div class="summary">Total payé : ' . $escape(number_format((float) $archive['finance_total_paye'], 2, ',', ' ')) . ' DT<br>
                Reste à payer : ' . $escape(number_format(max(0, (float) $archive['remaining_balance']), 2, ',', ' ')) . ' DT</div>
            <div class="footer">Gabès, le ' . date('d/m/Y') . '</div>
        </body></html>';
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $fileStudent = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $archive['cin_passeport']);
        $dompdf->stream('historique_paiements_' . $fileStudent . '_' . $archive['annee_scolaire'] . '.pdf', ['Attachment' => true]);
        exit;
    } else {
        $archive = $archives[0];
        $documentUrl = $exportAction === 'registration'
            ? 'attestation_inscription.php?' . http_build_query(['cin' => $archive['cin_passeport']])
            : 'releve_notes.php?' . http_build_query([
                'cin' => $archive['cin_passeport'],
                'year' => $archive['annee_scolaire'],
            ]);
        if ($embedded) {
            $documentUrl .= '&embed=1';
        }
        header('Location: ' . $documentUrl);
        exit;
    }
}

if ($format !== '') {
    if (!$selected) {
        $selected = array_map(static fn(array $archive): int => (int) $archive['id_archive'], $archives);
    }
    if (!$archives) {
        http_response_code(404);
        exit('Aucune donnée archivée trouvée.');
    }
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="etudiants_archives_' . ($year ?: 'export') . '.csv"');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Année scolaire', 'Nom', 'Prénom', 'Date naissance', 'Lieu naissance', 'CIN / passeport', 'N° inscription', 'Diplôme', 'Spécialité', 'Niveau', 'Décision', 'Moyenne générale', 'Total payé', 'Reste à payer', 'Résultat paiement', 'Historique paiements', 'Relevé de notes complet'], ';');
        foreach ($archives as $archive) {
            $payload = json_decode((string) $archive['student_payload'], true);
            $payload = is_array($payload) ? $payload : [];
            $history = $paymentHistories[(int) $archive['id_archive']] ?? [];
            $historyText = [];
            foreach ($history as $payment) {
                $historyText[] = ($payment['payment_created_at'] ?? '') . ' | ' . $payment['mode_paiement'] . ' | ' . $payment['montant_paye'] . ' DT';
            }
            fputcsv($output, [
                $archive['annee_scolaire'], $payload['nom'] ?? '', $payload['prenom'] ?? '',
                $payload['date_naissance'] ?? '', $payload['lieu_naissance'] ?? '',
                $archive['cin_passeport'], $archive['num_inscription'], 'Licence en sciences infirmières',
                $archive['nom_spec'], $archive['niveau'], $archive['decision'],
                $archive['moyenne_generale'], $archive['finance_total_paye'],
                max(0, (float) $archive['remaining_balance']),
                (float) $archive['remaining_balance'] <= 0 ? 'Paiement terminé' : 'Paiement non terminé',
                implode(' || ', $historyText), $archive['transcript_payload'],
            ], ';');
        }
        fclose($output);
        exit;
    }
    if ($format === 'pdf') {
        require_once __DIR__ . '/vendor/autoload.php';
        $dompdf = new \Dompdf\Dompdf();
        $logoFile = institution_logo_file();
        $logoPath = str_replace('\\', '/', $logoFile['path']);
        $logoUri = 'file:///' . ltrim($logoPath, '/');
        $html = '<!doctype html><html><head><meta charset="UTF-8"><style>@page{size:A4 portrait;margin:5mm}body{font-family:DejaVu Sans,sans-serif;font-size:7px;color:#111;margin:0}.student-section{height:286mm;page-break-after:always;overflow:hidden}.student-section:last-child{page-break-after:auto}h1{font-size:13px;text-align:center;margin:0 0 3px}h2{font-size:10px;border:1px solid #222;padding:2px;margin:2px 0;background:#eee}h3{font-size:8px;margin:3px 0 1px;background:#d9d9d9;padding:2px;border:1px solid #222}.certificate{border:1px solid #222;padding:3px;line-height:1.08}.certificate p{margin:1px 0}.certificate-head{text-align:center;font-weight:bold;font-size:9px}.certificate-logo{width:38px;height:15px;object-fit:contain;vertical-align:middle}.transcript-head{display:flex;justify-content:space-between;border:1px solid #222;padding:2px;font-weight:bold}.semester-title{font-weight:bold;background:#eee;border:1px solid #222;border-bottom:0;padding:1px}.semester-average{float:right}table{border-collapse:collapse;width:100%;margin:0 0 2px;table-layout:fixed}th,td{border:1px solid #333;padding:1px 2px;text-align:left;line-height:1.05;word-wrap:break-word}th{background:#d9d9d9;font-size:6.5px}.marks-table th:nth-child(1){width:31%}.marks-table th:nth-child(2){width:10%}.marks-table th:nth-child(3){width:6%}.marks-table th:nth-child(4),.marks-table th:nth-child(5),.marks-table th:nth-child(6),.marks-table th:nth-child(7),.marks-table th:nth-child(8){width:7.4%}.finance-table th,.finance-table td{font-size:7px}.status-ok{color:#087f23;font-weight:bold}.status-due{color:#b00020;font-weight:bold}.summary{border:1px solid #222;padding:2px;font-weight:bold;margin-top:2px}</style></head><body>';
        foreach ($archives as $archive) {
            $payload = json_decode((string) $archive['student_payload'], true);
            $payload = is_array($payload) ? $payload : [];
            $name = trim((string) ($payload['nom'] ?? '') . ' ' . (string) ($payload['prenom'] ?? ''));
            $birthDate = (string) ($payload['date_naissance'] ?? '');
            $birthTimestamp = $birthDate !== '' ? strtotime($birthDate) : false;
            if ($birthTimestamp !== false) $birthDate = date('d/m/Y', $birthTimestamp);
            $statusClass = (float) $archive['remaining_balance'] <= 0 ? 'status-ok' : 'status-due';
            $statusText = (float) $archive['remaining_balance'] <= 0 ? 'Paiement terminé' : 'Paiement non terminé';
            $html .= '<section class="student-section"><h1>ARCHIVE COMPLÈTE — ' . e($year) . '</h1><h2>' . e($name) . ' — ' . e($archive['cin_passeport']) . '</h2>';
            $html .= '<h3>Attestation d’inscription — modèle template_inscription_2.docx</h3><div class="certificate"><p class="certificate-head"><img class="certificate-logo" src="' . e($logoUri) . '"> CERTIFICAT D’INSCRIPTION</p><p class="certificate-head">' . e(institution_name_inline()) . '</p><p>Le secrétaire général de l’Université certifie que l’étudiant :</p>';
            $html .= '<p><b>Nom et prénom :</b> ' . e((string) ($payload['prenom'] ?? '') . ' ' . (string) ($payload['nom'] ?? '')) . '</p><p><b>Date et lieu de naissance :</b> ' . e($birthDate) . ' à ' . e((string) ($payload['lieu_naissance'] ?? '')) . '</p><p><b>N° CIN / N° Passeport :</b> ' . e($archive['cin_passeport']) . '</p><p><b>Diplôme :</b> Licence en sciences infirmières</p><p><b>Filière / Spécialité :</b> ' . e($archive['nom_spec']) . '</p><p><b>Niveau d’études :</b> ' . e(level_label((string) $archive['niveau'])) . '</p><p><b>Numéro d’inscription :</b> ' . e($archive['num_inscription']) . '</p><p>est inscrit(e) au sein de l’établissement au titre de l’année universitaire ' . e($archive['annee_scolaire']) . '.</p><p>Gabès, le ' . date('d/m/Y') . '</p><p><b>Le Secrétaire général</b><br>' . e(institution_brand()['sec_generale']) . '</p></div>';
            $html .= '<h3>Relevé de notes complet</h3><div class="transcript-head"><span>RELEVÉ DE NOTES — ' . e($archive['annee_scolaire']) . '</span><span>Moyenne générale : ' . e((string) ($archive['moyenne_generale'] ?? '—')) . '</span></div>';
            $marks = json_decode((string) $archive['transcript_payload'], true);
            $marks = is_array($marks) ? $marks : [];
            $bySemester = [];
            foreach ($marks as $mark) $bySemester[(string) ($mark['semestre'] ?? '')][] = $mark;
            foreach ($bySemester as $semester => $semesterMarks) {
                $semesterWeighted = 0.0;
                $semesterCoefficients = 0.0;
                foreach ($semesterMarks as $mark) {
                    if (isset($mark['note'], $mark['coeff']) && is_numeric($mark['note']) && is_numeric($mark['coeff'])) {
                        $semesterWeighted += (float) $mark['note'] * (float) $mark['coeff'];
                        $semesterCoefficients += (float) $mark['coeff'];
                    }
                }
                $semesterAverage = $semesterCoefficients > 0 ? number_format($semesterWeighted / $semesterCoefficients, 2, ',', ' ') : '—';
                $html .= '<div class="semester-title">' . e($semester) . '<span class="semester-average">Moyenne : ' . e($semesterAverage) . '</span></div><table class="marks-table"><thead><tr><th>Matière</th><th>Code</th><th>Coef</th><th>CC</th><th>Examen</th><th>TP</th><th>Moy.</th><th>Rattr.</th></tr></thead><tbody>';
                foreach ($semesterMarks as $mark) {
                    $html .= '<tr><td>' . e((string) ($mark['description_ecue'] ?? $mark['description_ue'] ?? '')) . '</td><td>' . e((string) ($mark['code_ecue'] ?? $mark['code_ue'] ?? '')) . '</td><td>' . e((string) ($mark['coeff'] ?? '')) . '</td><td>' . e((string) ($mark['note_cours'] ?? '')) . '</td><td>' . e((string) ($mark['note_td'] ?? '')) . '</td><td>' . e((string) ($mark['note_tp'] ?? '')) . '</td><td>' . e((string) ($mark['note'] ?? '')) . '</td><td>' . e((string) ($mark['note_rattrapage'] ?? '')) . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }
            $html .= '<div class="summary">Décision : ' . e((string) $archive['decision']) . ' — Moyenne générale : ' . e((string) ($archive['moyenne_generale'] ?? '—')) . '</div>';
            $html .= '<h3>Historique complet des paiements</h3><table><thead><tr><th>Date</th><th>Mode</th><th>Montant</th></tr></thead><tbody>';
            foreach ($paymentHistories[(int) $archive['id_archive']] ?? [] as $payment) {
                $html .= '<tr><td>' . e((string) ($payment['payment_created_at'] ?? '')) . '</td><td>' . e((string) $payment['mode_paiement']) . '</td><td>' . e((string) $payment['montant_paye']) . ' DT</td></tr>';
            }
            $html .= '</tbody></table><div class="summary">Total payé : ' . e((string) $archive['finance_total_paye']) . ' DT — Reste : ' . e((string) max(0, (float) $archive['remaining_balance'])) . ' DT — <span class="' . $statusClass . '">' . $statusText . '</span></div></section>';
        }
        $dompdf->loadHtml($html . '</body></html>', 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        if (!empty($logoFile['temporary']) && is_file($logoFile['path'])) {
            unlink($logoFile['path']);
        }
        $dompdf->stream('etudiants_archives_' . ($year ?: 'export') . '.pdf', ['Attachment' => true]);
        exit;
    }
    http_response_code(400);
    exit('Format d’export invalide.');
}

$years = $availableYears;
$groups = $pdo->query("SELECT id_groupe, semestre, type_groupe, numero FROM groupe WHERE type_groupe = 'Cours' ORDER BY semestre, numero")->fetchAll();
page_head('Export des archives', 'archive-export');

$renderArchiveRows = static function (array $rows, array $selectedIds): void {
    foreach ($rows as $archive) {
        $payload = json_decode((string) $archive['student_payload'], true);
        $name = is_array($payload) ? trim((string) ($payload['nom'] ?? '') . ' ' . (string) ($payload['prenom'] ?? '')) : '—';
        $checked = in_array((int) $archive['id_archive'], $selectedIds, true) ? ' checked' : '';
        echo '<tr class="archive-row' . ($checked !== '' ? ' archive-row-pinned' : '') . '">'
            . '<td><input class="archive-checkbox" type="checkbox" name="archive_ids[]" value="' . (int) $archive['id_archive'] . '"' . $checked . '></td>'
            . '<td>' . e($name) . '</td>'
            . '<td>' . e((string) $archive['cin_passeport']) . '</td>'
            . '<td>' . e((string) $archive['num_inscription']) . '</td>'
            . '<td>' . e((string) $archive['niveau']) . '</td>'
            . '<td>' . e((string) $archive['decision']) . '</td>'
            . '<td>' . ($archive['moyenne_generale'] === null ? '—' : e(number_format((float) $archive['moyenne_generale'], 2, ',', ' '))) . '</td>'
            . '<td>' . e(number_format((float) $archive['finance_total_paye'], 2, ',', ' ')) . ' DT</td>'
            . '</tr>';
    }
};
$showPinnedBlock = !$isExporting && $pinnedArchives !== [];
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><p class="eyebrow">Administration</p><h1 class="display-6 fw-bold mb-1">Exporter les archives</h1><p class="text-secondary mb-0">Choisissez une année et les étudiants à télécharger.</p></div></div>
<form method="get" class="card app-card mb-4" id="archive-filter-form"><div class="card-body p-4"><input type="hidden" name="embed" value="<?= $embedded ? '1' : '' ?>"><div id="archive-filter-selected"></div><div class="row g-3 align-items-end"><div class="col-md-3"><label class="form-label" for="year">Année d’archive</label><select class="form-select" id="year" name="year" required><option value="">Choisir</option><?php foreach ($years as $archiveYear): ?><option value="<?= e($archiveYear) ?>" <?= $year === $archiveYear ? 'selected' : '' ?>><?= e($archiveYear) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label" for="group_id">Groupe</label><select class="form-select" id="group_id" name="group_id"><option value="0">Tous les groupes</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id_groupe'] ?>" <?= $groupId === (int) $group['id_groupe'] ? 'selected' : '' ?>><?= e($group['semestre'] . ' — ' . $group['type_groupe'] . ' ' . $group['numero']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label" for="search">CIN / passeport ou nom</label><input class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Rechercher"></div><div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Afficher</button></div></div></div></form>
<?php if ($exportError): ?><div class="alert alert-danger"><?= e($exportError) ?></div><?php endif; ?>
<?php if ($search !== '' && !$showPinnedBlock && $archives): ?><div class="alert alert-warning">Aucun étudiant ne correspond à « <?= e($search) ?> » dans cette liste.</div><?php endif; ?>
<form method="get" id="archive-export-form"><input type="hidden" name="year" value="<?= e($year) ?>"><input type="hidden" name="group_id" value="<?= (int) $groupId ?>"><input type="hidden" name="search" value="<?= e($search) ?>"><input type="hidden" name="embed" value="<?= $embedded ? '1' : '' ?>">
<div class="card app-card mb-4">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-5">
        <label class="form-label" for="document-choice">Document à générer pour un seul étudiant</label>
        <select class="form-select" id="document-choice" name="export_action">
          <option value="">Choisir un document</option>
          <option value="registration">Attestation d’inscription — aperçu et téléchargement Word</option>
          <option value="transcript">Relevé de notes archivé — aperçu et impression comme dans le tableau de bord</option>
          <option value="payment_history">Historique des paiements archivé — télécharger PDF</option>
        </select>
      </div>
      <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Ouvrir le document</button></div>
      <div class="col-md-4 d-flex gap-2 justify-content-end">
        <button class="btn btn-outline-success" name="format" value="csv" <?= !$archives ? 'disabled' : '' ?>>Télécharger CSV</button>
        <button class="btn btn-outline-danger" name="format" value="pdf" <?= !$archives ? 'disabled' : '' ?>>Télécharger PDF</button>
      </div>
    </div>
  </div>
</div>
<?php if ($showPinnedBlock): ?>
<div class="card app-card mb-4" id="archive-pinned-panel">
  <div class="card-body py-3 border-bottom"><strong>Étudiant(s) trouvé(s)</strong><span class="text-secondary ms-2"><?= count($pinnedArchives) ?></span></div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead><tr><th></th><th>Étudiant</th><th>CIN / passeport</th><th>Inscription</th><th>Niveau</th><th>Décision</th><th>Moyenne</th><th>Finance</th></tr></thead>
      <tbody><?php $renderArchiveRows($pinnedArchives, $selected); ?></tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<div class="card app-card">
  <?php if ($showPinnedBlock && $otherArchives): ?><div class="card-body py-3 border-bottom"><strong>Autres étudiants</strong></div><?php endif; ?>
  <div class="card-body p-0 table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead><tr><th><input type="checkbox" id="select-all"></th><th>Étudiant</th><th>CIN / passeport</th><th>Inscription</th><th>Niveau</th><th>Décision</th><th>Moyenne</th><th>Finance</th></tr></thead>
      <tbody>
        <?php
        if ($showPinnedBlock) {
            $renderArchiveRows($otherArchives, $selected);
            if (!$otherArchives && !$pinnedArchives) {
                echo '<tr><td colspan="8" class="text-center text-secondary py-4">Aucune archive trouvée.</td></tr>';
            } elseif (!$otherArchives) {
                echo '<tr><td colspan="8" class="text-center text-secondary py-4">Tous les résultats sont ci-dessus.</td></tr>';
            }
        } else {
            $renderArchiveRows($archives, $selected);
            if (!$archives) {
                echo '<tr><td colspan="8" class="text-center text-secondary py-4">Aucune archive trouvée.</td></tr>';
            }
        }
        ?>
      </tbody>
    </table>
  </div>
</div>
</form>
<script>
(function () {
  const filterForm = document.getElementById('archive-filter-form');
  const selectedBox = document.getElementById('archive-filter-selected');
  document.getElementById('select-all')?.addEventListener('change', function () {
    document.querySelectorAll('.archive-checkbox').forEach(function (checkbox) { checkbox.checked = this.checked; }, this);
  });
  filterForm?.addEventListener('submit', function () {
    if (!selectedBox) return;
    selectedBox.innerHTML = '';
    document.querySelectorAll('.archive-checkbox:checked').forEach(function (checkbox) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'archive_ids[]';
      input.value = checkbox.value;
      selectedBox.appendChild(input);
    });
  });
})();
</script>
<?php page_foot(); ?>
