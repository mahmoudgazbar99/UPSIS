<?php
declare(strict_types=1);

/**
 * Inscription rules: required fields, document checklist, open/close window.
 */

function inscription_field_catalog(): array
{
    return [
        'nom' => ['label' => 'Nom', 'locked' => true],
        'prenom' => ['label' => 'Prénom', 'locked' => true],
        'cin_passeport' => ['label' => 'CIN / Passeport', 'locked' => true],
        'id_spec' => ['label' => 'Spécialité', 'locked' => true],
        'niveau' => ['label' => 'Niveau', 'locked' => true],
        'nom_ar' => ['label' => 'Nom arabe', 'locked' => false],
        'prenom_ar' => ['label' => 'Prénom arabe', 'locked' => false],
        'date_naissance' => ['label' => 'Date de naissance', 'locked' => false],
        'lieu_naissance' => ['label' => 'Lieu de naissance', 'locked' => false],
        'lieu_naissance_ar' => ['label' => 'Lieu de naissance (arabe)', 'locked' => false],
        'sexe' => ['label' => 'Sexe', 'locked' => false],
        'nationalite' => ['label' => 'Nationalité', 'locked' => false],
        'etat_civil' => ['label' => 'État civil', 'locked' => false],
        'num_tel' => ['label' => 'Téléphone', 'locked' => false],
        'email' => ['label' => 'E-mail', 'locked' => false],
        'adresse' => ['label' => 'Adresse', 'locked' => false],
        'date_inscrit' => ['label' => 'Date d’inscription', 'locked' => false],
        'annee_bac' => ['label' => 'Année du bac', 'locked' => false],
        'specialite_bac' => ['label' => 'Spécialité du bac', 'locked' => false],
    ];
}

function inscription_policy_defaults(): array
{
    $required = [];
    foreach (inscription_field_catalog() as $key => $_meta) {
        $required[$key] = true;
    }
    return [
        'required_fields' => $required,
        'documents' => [
            ['code' => 'cin', 'label' => 'Copie CIN / passeport', 'required' => true],
            ['code' => 'photo', 'label' => 'Photo d’identité', 'required' => true],
            ['code' => 'bac', 'label' => 'Diplôme / relevé du bac', 'required' => true],
            ['code' => 'autre', 'label' => 'Autre pièce (si demandé)', 'required' => false],
        ],
        'open_from' => '',
        'open_to' => '',
    ];
}

function inscription_policy_ensure(): void
{
    institution_ensure();
    $defaults = inscription_policy_defaults();
    $insert = db()->prepare('INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
    $insert->execute(['inscription_required_fields', json_encode($defaults['required_fields'], JSON_UNESCAPED_UNICODE)]);
    $insert->execute(['inscription_documents', json_encode($defaults['documents'], JSON_UNESCAPED_UNICODE)]);
    $insert->execute(['inscription_open_from', '']);
    $insert->execute(['inscription_open_to', '']);
}

function inscription_policy(): array
{
    inscription_policy_ensure();
    $defaults = inscription_policy_defaults();
    $catalog = inscription_field_catalog();

    $rawFields = trim(institution_setting('inscription_required_fields'));
    $decodedFields = $rawFields !== '' ? json_decode($rawFields, true) : null;
    $required = $defaults['required_fields'];
    if (is_array($decodedFields)) {
        foreach ($catalog as $key => $meta) {
            if (!empty($meta['locked'])) {
                $required[$key] = true;
                continue;
            }
            if (array_key_exists($key, $decodedFields)) {
                $required[$key] = (bool) $decodedFields[$key];
            }
        }
    }

    $rawDocs = trim(institution_setting('inscription_documents'));
    $decodedDocs = $rawDocs !== '' ? json_decode($rawDocs, true) : null;
    $documents = $defaults['documents'];
    if (is_array($decodedDocs)) {
        $documents = [];
        foreach ($decodedDocs as $index => $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $label = trim((string) ($doc['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $code = trim((string) ($doc['code'] ?? ''));
            if ($code === '' || !preg_match('/^[a-z0-9_\-]{1,32}$/i', $code)) {
                $code = 'doc' . ($index + 1);
            }
            $documents[] = [
                'code' => strtolower($code),
                'label' => mb_substr($label, 0, 120),
                'required' => !empty($doc['required']),
            ];
        }
        if ($documents === []) {
            $documents = $defaults['documents'];
        }
    }

    $openFrom = trim(institution_setting('inscription_open_from'));
    $openTo = trim(institution_setting('inscription_open_to'));
    if ($openFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $openFrom)) {
        $openFrom = '';
    }
    if ($openTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $openTo)) {
        $openTo = '';
    }

    return [
        'required_fields' => $required,
        'documents' => $documents,
        'open_from' => $openFrom,
        'open_to' => $openTo,
    ];
}

function inscription_field_required(string $field): bool
{
    $policy = inscription_policy();
    $catalog = inscription_field_catalog();
    if (!isset($catalog[$field])) {
        return false;
    }
    if (!empty($catalog[$field]['locked'])) {
        return true;
    }
    return !empty($policy['required_fields'][$field]);
}

function inscription_required_field_keys(): array
{
    $keys = [];
    foreach (inscription_field_catalog() as $key => $_meta) {
        if (inscription_field_required($key)) {
            $keys[] = $key;
        }
    }
    return $keys;
}

function inscription_documents(): array
{
    return inscription_policy()['documents'];
}

function inscription_window_open(?string $today = null): bool
{
    $policy = inscription_policy();
    $today = $today ?: date('Y-m-d');
    $from = $policy['open_from'];
    $to = $policy['open_to'];
    if ($from === '' && $to === '') {
        return true;
    }
    if ($from !== '' && $today < $from) {
        return false;
    }
    if ($to !== '' && $today > $to) {
        return false;
    }
    return true;
}

function inscription_window_message(): string
{
    $policy = inscription_policy();
    $from = $policy['open_from'];
    $to = $policy['open_to'];
    if ($from === '' && $to === '') {
        return 'Les nouvelles inscriptions sont ouvertes.';
    }
    if ($from !== '' && $to !== '') {
        return 'Période d’inscription : du ' . $from . ' au ' . $to . '.';
    }
    if ($from !== '') {
        return 'Inscriptions ouvertes à partir du ' . $from . '.';
    }
    return 'Inscriptions ouvertes jusqu’au ' . $to . '.';
}

function inscription_save_policy(array $requiredFields, array $documents, string $openFrom, string $openTo): void
{
    $catalog = inscription_field_catalog();
    $required = [];
    foreach ($catalog as $key => $meta) {
        if (!empty($meta['locked'])) {
            $required[$key] = true;
            continue;
        }
        $required[$key] = !empty($requiredFields[$key]);
    }

    $cleanDocs = [];
    $seen = [];
    foreach ($documents as $index => $doc) {
        if (!is_array($doc)) {
            continue;
        }
        $label = trim((string) ($doc['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        $code = strtolower(trim((string) ($doc['code'] ?? '')));
        if ($code === '' || !preg_match('/^[a-z0-9_\-]{1,32}$/', $code)) {
            $code = 'doc' . ($index + 1);
        }
        if (isset($seen[$code])) {
            $code .= '_' . ($index + 1);
        }
        $seen[$code] = true;
        $cleanDocs[] = [
            'code' => $code,
            'label' => mb_substr($label, 0, 120),
            'required' => !empty($doc['required']),
        ];
    }
    if ($cleanDocs === []) {
        throw new InvalidArgumentException('Ajoutez au moins une pièce au checklist documents.');
    }

    $openFrom = trim($openFrom);
    $openTo = trim($openTo);
    if ($openFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $openFrom)) {
        throw new InvalidArgumentException('Date de début invalide.');
    }
    if ($openTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $openTo)) {
        throw new InvalidArgumentException('Date de fin invalide.');
    }
    if ($openFrom !== '' && $openTo !== '' && $openTo < $openFrom) {
        throw new InvalidArgumentException('La date de fin doit être après la date de début.');
    }

    inscription_policy_ensure();
    institution_setting_put('inscription_required_fields', json_encode($required, JSON_UNESCAPED_UNICODE));
    institution_setting_put('inscription_documents', json_encode($cleanDocs, JSON_UNESCAPED_UNICODE));
    institution_setting_put('inscription_open_from', $openFrom);
    institution_setting_put('inscription_open_to', $openTo);
    academic_policy_note('inscription_regles', 'Champs / docs / dates d’inscription mis à jour');
}

function inscription_missing_required(array $data): array
{
    $missing = [];
    foreach (inscription_required_field_keys() as $field) {
        $value = trim((string) ($data[$field] ?? ''));
        if ($value === '') {
            $missing[] = $field;
        }
    }
    return $missing;
}

function inscription_docs_checked(array $postedCodes): array
{
    $checked = [];
    foreach ($postedCodes as $code) {
        $code = strtolower(trim((string) $code));
        if ($code !== '') {
            $checked[$code] = true;
        }
    }
    return $checked;
}

function inscription_missing_documents(array $checkedCodes): array
{
    $missing = [];
    $checked = inscription_docs_checked($checkedCodes);
    foreach (inscription_documents() as $doc) {
        if (!empty($doc['required']) && empty($checked[$doc['code']])) {
            $missing[] = $doc;
        }
    }
    return $missing;
}
