<?php
declare(strict_types=1);

/**
 * Tronc commun S1 timetable.
 * Handwritten blue-pen volumes win over the printed sheet.
 * Limits that cannot be met are recorded, not invented away.
 */

function tc_spread_weeks(int $count): array
{
    if ($count <= 0) {
        return [];
    }
    if ($count >= 14) {
        return range(1, 14);
    }
    if ($count === 7) {
        return [2, 4, 6, 8, 10, 12, 14];
    }
    $weeks = [];
    $steps = max(1, $count - 1);
    for ($i = 0; $i < $count; $i++) {
        $week = (int) round(1 + $i * 13 / $steps);
        $weeks[max(1, min(14, $week))] = true;
    }
    for ($week = 14; count($weeks) < $count && $week >= 1; $week--) {
        $weeks[$week] = true;
    }
    $list = array_keys($weeks);
    sort($list);
    return $list;
}

function tc_minutes(string $hhmm): int
{
    [$h, $m] = array_map('intval', explode(':', substr($hhmm, 0, 5)));
    return $h * 60 + $m;
}

function tc_hhmm(int $minutes): string
{
    return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
}

function tc_subjects(): array
{
    return [
        ['code' => 'UEF111', 'name' => 'Anatomie-physiologie 1', 'ue' => 'UEF110', 'ue_name' => 'Sciences biomédicales 1', 'cours' => 42.0, 'td' => 27.0, 'tp' => 0.0, 'stage' => 0.0, 'pen' => 'TD imprimé 28 barré, stylo 27. Total imprimé 70 barré, stylo 69.'],
        ['code' => 'UEF121', 'name' => 'Déontologie - Ethique', 'ue' => 'UEF120', 'ue_name' => 'Discipline infirmière - Déontologie - Ethique', 'cours' => 24.0, 'td' => 0.0, 'tp' => 0.0, 'stage' => 0.0, 'pen' => ''],
        ['code' => 'UEF122', 'name' => 'Introduction à la discipline infirmière', 'ue' => 'UEF120', 'ue_name' => 'Discipline infirmière - Déontologie - Ethique', 'cours' => 30.0, 'td' => 0.0, 'tp' => 0.0, 'stage' => 0.0, 'pen' => ''],
        ['code' => 'UEF131', 'name' => 'Techniques infirmières 1', 'ue' => 'UEF130', 'ue_name' => 'Méthodes d\'interventions infirmières 1', 'cours' => 6.0, 'td' => 36.0, 'tp' => 45.0, 'stage' => 0.0, 'pen' => 'Autre imprimé 0. TP 45 h pour chaque groupe concerné.'],
        ['code' => 'UEF132', 'name' => 'Démarche de soins', 'ue' => 'UEF130', 'ue_name' => 'Méthodes d\'interventions infirmières 1', 'cours' => 21.0, 'td' => 21.0, 'tp' => 0.0, 'stage' => 14.0, 'pen' => 'Cr imprimé 20 barré, stylo 21. TD imprimé 22 barré, stylo 21. Autre 14 = stage.'],
        ['code' => 'UEF141', 'name' => 'Soins dans la communauté 1', 'ue' => 'UEF140', 'ue_name' => 'Soins infirmiers en santé communautaire', 'cours' => 21.0, 'td' => 9.0, 'tp' => 0.0, 'stage' => 12.0, 'pen' => 'Autre 12 = stage.'],
        ['code' => 'UEF142', 'name' => 'Microbiologie, parasitologie et immunité', 'ue' => 'UEF140', 'ue_name' => 'Soins infirmiers en santé communautaire', 'cours' => 21.0, 'td' => 9.0, 'tp' => 0.0, 'stage' => 0.0, 'pen' => 'Code ECUE illisible sur la photo. UEF142 est une étiquette provisoire, pas un code lu.'],
        ['code' => 'UET121', 'name' => 'Psychologie du développement et développement social', 'ue' => 'UET120', 'ue_name' => 'Techniques de communication et Psychologie du développement social', 'cours' => 21.0, 'td' => 0.0, 'tp' => 0.0, 'stage' => 0.0, 'pen' => ''],
        ['code' => 'UET122', 'name' => 'Techniques de communication 1', 'ue' => 'UET120', 'ue_name' => 'Techniques de communication et Psychologie du développement social', 'cours' => 21.0, 'td' => 0.0, 'tp' => 0.0, 'stage' => 0.0, 'pen' => ''],
        ['code' => 'UET111', 'name' => 'Français Professionnel 1', 'ue' => 'UET110', 'ue_name' => 'Formation Transversale 1', 'cours' => 21.0, 'td' => 0.0, 'tp' => 0.0, 'stage' => 0.0, 'pen' => ''],
        ['code' => 'UET112', 'name' => 'Digital (Certif 2CN)', 'ue' => 'UET110', 'ue_name' => 'Formation Transversale 1', 'cours' => 21.0, 'td' => 0.0, 'tp' => 0.0, 'stage' => 0.0, 'pen' => 'Case TP vide. Aucune heure de TP informatique n\'est programmée.'],
        ['code' => 'UET113', 'name' => 'Study Skills (Univ)', 'ue' => 'UET110', 'ue_name' => 'Formation Transversale 1', 'cours' => 21.0, 'td' => 0.0, 'tp' => 0.0, 'stage' => 0.0, 'pen' => ''],
    ];
}

function tc_notes(): array
{
    return [
        'Le stylo prime sur l\'imprimé : Anatomie TD 27 h (28 barré), total 69 h. Démarche de soins : cours 21 h et TD 21 h.',
        'Stage = colonne Autre. Démarche de soins 14 h, Soins dans la communauté 1 : 12 h. Créneau imposé 14:00–18:00. 14 n\'est pas un multiple de 4 : 3 séances font 12 h, il reste 2 h non placées.',
        'TP Techniques infirmières : 45 h par groupe, séances de 3 h, deux laboratoires. Deux groupes peuvent travailler en même temps, un par laboratoire.',
        'Le rattachement TP1–TP12 aux groupes TD n\'est pas sur la feuille. Répartition provisoire : G1 = TP1–TP3, G2 = TP4–TP6, G3 = TP7–TP9, G4 = TP10–TP12. À confirmer.',
        'Aucun nom d\'enseignant ni de salle de stage n\'est écrit sur la feuille. Les noms affichés sont les comptes déjà présents dans l\'application, pour que le planning puisse être enregistré.',
        'Digital (Certif 2CN) n\'a pas d\'heures TP sur la feuille. Le laboratoire informatique n\'est pas utilisé.',
        'G1 et G2 ne peuvent partager un TD (25 places). Le commun réel est le cours, plus le stage calé aux mêmes après-midi. Le 70 % exact n\'est pas forcé.',
    ];
}

function tc_ensure_schema(PDO $pdo): void
{
    $type = (string) $pdo->query("SHOW COLUMNS FROM emploi_temps LIKE 'type_seance'")->fetch()['Type'];
    if (!str_contains($type, 'Stage')) {
        $pdo->exec("ALTER TABLE emploi_temps MODIFY type_seance ENUM('Cours','TD','TP','Stage') NOT NULL");
    }
    $week = $pdo->query("SHOW COLUMNS FROM emploi_temps LIKE 'semaine'")->fetch();
    if (!$week) {
        $pdo->exec('ALTER TABLE emploi_temps ADD COLUMN semaine TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER jour');
    }
}

function tc_ensure_data(PDO $pdo): array
{
    tc_ensure_schema($pdo);
    foreach (tc_subjects() as $subject) {
        $existing = $pdo->prepare('SELECT id_mat FROM matiere WHERE code_ecue = ? AND semestre = "S1"');
        $existing->execute([$subject['code']]);
        $id = (int) $existing->fetchColumn();
        if ($id) {
            $pdo->prepare('UPDATE matiere SET description_ecue = ?, description_ue = ?, code_ue = ?, volume_cours = ?, volume_td = ?, volume_tp = ?, volume_autres = ? WHERE id_mat = ?')
                ->execute([$subject['name'], $subject['ue_name'], $subject['ue'], $subject['cours'], $subject['td'], $subject['tp'], $subject['stage'], $id]);
        } else {
            $pdo->prepare('INSERT INTO matiere (code_ue, description_ue, code_ecue, description_ecue, semestre, coeff, credit, regime, volume_cours, volume_td, volume_tp, volume_autres, coefficient_ecue, coefficient_ue) VALUES (?,?,?,?, "S1", 1, 1, "Mixte", ?,?,?,?, 1, 1)')
                ->execute([$subject['ue'], $subject['ue_name'], $subject['code'], $subject['name'], $subject['cours'], $subject['td'], $subject['tp'], $subject['stage']]);
        }
    }
    $ids = [];
    foreach (tc_subjects() as $subject) {
        $q = $pdo->prepare('SELECT id_mat FROM matiere WHERE code_ecue = ? AND semestre = "S1"');
        $q->execute([$subject['code']]);
        $ids[$subject['code']] = (int) $q->fetchColumn();
    }
    $groups = [];
    for ($n = 1; $n <= 4; $n++) {
        $q = $pdo->prepare('SELECT id_groupe FROM groupe WHERE semestre = "S1" AND type_groupe = "TD" AND numero = ?');
        $q->execute([$n]);
        $id = (int) $q->fetchColumn();
        if (!$id) {
            $pdo->prepare('INSERT INTO groupe (semestre, type_groupe, numero, capacite) VALUES ("S1", "TD", ?, 25)')->execute([$n]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE groupe SET capacite = 25 WHERE id_groupe = ?')->execute([$id]);
        }
        $groups['G' . $n] = $id;
    }
    return ['subjects' => $ids, 'groups' => $groups];
}

function tc_build(PDO $pdo): array
{
    $meta = tc_ensure_data($pdo);
    $subjectIds = $meta['subjects'];
    $groups = $meta['groups'];
    $days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $patterns = [
        [tc_minutes('08:15'), tc_minutes('09:45'), tc_minutes('11:15')],
        [tc_minutes('09:00'), tc_minutes('10:30'), tc_minutes('11:15')],
        [tc_minutes('08:15'), tc_minutes('09:45'), tc_minutes('11:15')],
        [tc_minutes('09:30'), tc_minutes('11:00')],
        [tc_minutes('10:00'), tc_minutes('11:30')],
        [tc_minutes('09:00'), tc_minutes('10:30')],
    ];
    $coursTeachers = $pdo->query('SELECT id_enseignant FROM enseignant WHERE type_enseignant = "Cours" ORDER BY id_enseignant')->fetchAll(PDO::FETCH_COLUMN);
    $tdTeachers = $pdo->query('SELECT id_enseignant FROM enseignant WHERE type_enseignant = "TD" ORDER BY id_enseignant')->fetchAll(PDO::FETCH_COLUMN);
    $tpTeacher = (int) $pdo->query('SELECT id_enseignant FROM enseignant WHERE type_enseignant = "TP" ORDER BY id_enseignant LIMIT 1')->fetchColumn();
    $names = [];
    foreach ($pdo->query('SELECT id_enseignant, prenom, nom FROM enseignant') as $row) {
        $names[(int) $row['id_enseignant']] = trim($row['prenom'] . ' ' . $row['nom']);
    }
    $byCode = [];
    foreach (tc_subjects() as $subject) {
        $byCode[$subject['code']] = $subject;
    }

    $cal = [];
    $rows = [];
    $free = static function (array $cal, string $key, int $start, int $end): bool {
        foreach ($cal[$key] ?? [] as [$a, $b]) {
            if ($start < $b && $a < $end) {
                return false;
            }
        }
        return true;
    };
    $book = static function (array &$cal, string $key, int $start, int $end): void {
        $cal[$key][] = [$start, $end];
    };
    $gkey = static fn (int $groupId, int $week, int $day): string => "g{$groupId}|{$week}|{$day}";
    $dayEnd = static function (array $cal, array $groupIds, int $week, int $day) use ($gkey): int {
        $end = 0;
        foreach ($groupIds as $groupId) {
            foreach ($cal[$gkey($groupId, $week, $day)] ?? [] as [, $b]) {
                $end = max($end, $b);
            }
        }
        return $end;
    };
    $dayStart = static function (array $cal, array $groupIds, int $week, int $day) use ($gkey): int {
        $start = 24 * 60;
        $any = false;
        foreach ($groupIds as $groupId) {
            foreach ($cal[$gkey($groupId, $week, $day)] ?? [] as [$a] ) {
                $any = true;
                $start = min($start, $a);
            }
        }
        return $any ? $start : 0;
    };

    $push = static function (array &$rows, array &$cal, array $item) use ($book, $gkey): void {
        $rows[] = $item;
        foreach ($item['groups'] as $groupId) {
            $book($cal, $gkey($groupId, $item['week'], $item['day']), $item['start'], $item['end']);
        }
        $book($cal, 'room|' . $item['room'] . '|' . $item['week'] . '|' . $item['day'], $item['start'], $item['end']);
        $book($cal, 'teacher|' . $item['teacher'] . '|' . $item['week'] . '|' . $item['day'], $item['start'], $item['end']);
        if ($item['lab']) {
            $book($cal, 'lab|' . $item['lab'] . '|' . $item['week'] . '|' . $item['day'], $item['start'], $item['end']);
        }
    };

    $tpSets = [
        'G1' => ['TP1', 'TP2', 'TP3'],
        'G2' => ['TP4', 'TP5', 'TP6'],
        'G3' => ['TP7', 'TP8', 'TP9'],
        'G4' => ['TP10', 'TP11', 'TP12'],
    ];
    $tpList = [];
    foreach ($tpSets as $label => $namesTp) {
        foreach ($namesTp as $tpName) {
            $tpList[] = ['tp' => $tpName, 'label' => $label, 'group' => $groups[$label]];
        }
    }
    $unplaced = [];
    $labs = ['Labo TP Technique 1', 'Labo TP Technique 2'];
    $tpStaff = $pdo->query('SELECT id_enseignant FROM enseignant WHERE type_enseignant = "TP" ORDER BY id_enseignant')->fetchAll(PDO::FETCH_COLUMN);
    if (count($tpStaff) < 2) {
        foreach ($pdo->query('SELECT id_enseignant FROM enseignant WHERE type_enseignant <> "TP" ORDER BY type_enseignant, id_enseignant') as $extraTeacher) {
            $tpStaff[] = (int) $extraTeacher['id_enseignant'];
            if (count($tpStaff) >= 2) {
                break;
            }
        }
    }
    $tpSlot = [
        'TP1' => [0, 0], 'TP2' => [2, 0], 'TP3' => [4, 0],
        'TP4' => [0, 1], 'TP5' => [2, 1], 'TP6' => [4, 1],
        'TP7' => [1, 0], 'TP8' => [3, 0], 'TP9' => [5, 0],
        'TP10' => [1, 1], 'TP11' => [3, 1], 'TP12' => [5, 1],
    ];
    foreach ($tpList as $index => $tp) {
        [$day, $labIndex] = $tpSlot[$tp['tp']];
        $labName = $labs[$labIndex];
        $teacherId = (int) $tpStaff[$labIndex % count($tpStaff)];
        $start = tc_minutes('15:00');
        $end = tc_minutes('18:00');
        for ($week = 1; $week <= 14; $week++) {
            $push($rows, $cal, [
                'week' => $week, 'day' => $day, 'start' => $start, 'end' => $end,
                'groups' => [$tp['group']], 'code' => 'UEF131', 'type' => 'TP',
                'room' => $labName, 'lab' => $labName, 'teacher' => $teacherId,
                'tp' => $tp['tp'], 'label' => $tp['label'],
            ]);
        }
        $extraWeek = $index + 1;
        $push($rows, $cal, [
            'week' => $extraWeek, 'day' => 5, 'start' => tc_minutes('08:15'), 'end' => tc_minutes('11:15'),
            'groups' => [$tp['group']], 'code' => 'UEF131', 'type' => 'TP',
            'room' => $labName, 'lab' => $labName, 'teacher' => $teacherId,
            'tp' => $tp['tp'], 'label' => $tp['label'],
        ]);
    }

    $stagePlan = [
        ['code' => 'UEF132', 'count' => 3, 'asked' => 14.0, 'placed_hours' => 12.0],
        ['code' => 'UEF141', 'count' => 3, 'asked' => 12.0, 'placed_hours' => 12.0],
    ];
    $stageSlots = [
        ['G1', 'G2', 0, 1, 'UEF132', [2, 8, 14]],
        ['G1', 'G2', 0, 5, 'UEF141', [4, 10, 14]],
        ['G3', 'G4', 1, 2, 'UEF132', [2, 8, 14]],
        ['G3', 'G4', 1, 4, 'UEF141', [4, 10, 14]],
    ];
    foreach ($stageSlots as [$leftLabel, $rightLabel, $teacherIndex, $stageDay, $code, $weeks]) {
        foreach ($weeks as $week) {
            $push($rows, $cal, [
                'week' => $week, 'day' => $stageDay, 'start' => tc_minutes('14:00'), 'end' => tc_minutes('18:00'),
                'groups' => [$groups[$leftLabel], $groups[$rightLabel]],
                'code' => $code, 'type' => 'Stage', 'room' => 'Stage · ' . $leftLabel . '+' . $rightLabel,
                'lab' => '', 'teacher' => (int) $tdTeachers[$teacherIndex % count($tdTeachers)], 'tp' => '', 'label' => $leftLabel . '+' . $rightLabel,
            ]);
        }
    }

    $teacherFor = [];
    $i = 0;
    foreach (array_keys($byCode) as $code) {
        $teacherFor[$code] = [
            (int) $coursTeachers[$i % count($coursTeachers)],
            (int) $coursTeachers[($i + 6) % count($coursTeachers)],
        ];
        $i++;
    }
    $tdFor = [
        'G1' => (int) $tdTeachers[0],
        'G2' => (int) $tdTeachers[1 % count($tdTeachers)],
        'G3' => (int) $tdTeachers[2 % count($tdTeachers)],
        'G4' => (int) $tdTeachers[3 % count($tdTeachers)],
    ];
    $roomsTd = ['G1' => 'Salle 1', 'G2' => 'Salle 2', 'G3' => 'Salle 3', 'G4' => 'Salle 4'];

    $fits = static function (array $cal, array $groupIds, int $teacherId, string $room, int $week, int $day, int $start, int $end, bool $strict) use ($free, $dayEnd, $gkey): bool {
        if (!$free($cal, 'room|' . $room . '|' . $week . '|' . $day, $start, $end)) {
            return false;
        }
        if (!$free($cal, 'teacher|' . $teacherId . '|' . $week . '|' . $day, $start, $end)) {
            return false;
        }
        foreach ($groupIds as $groupId) {
            if (!$free($cal, $gkey($groupId, $week, $day), $start, $end)) {
                return false;
            }
        }
        $morningEnd = 0;
        foreach ($groupIds as $groupId) {
            foreach ($cal[$gkey($groupId, $week, $day)] ?? [] as [$a, $b]) {
                if ($b <= tc_minutes('14:00')) {
                    $morningEnd = max($morningEnd, $b);
                }
            }
        }
        if ($start < tc_minutes('14:00') && ($morningEnd === 0 || $start === $morningEnd)) {
            return true;
        }
        if ($start === tc_minutes('15:00') && ($morningEnd === 0 || $morningEnd >= tc_minutes('12:30'))) {
            return true;
        }
        $hasLateAfternoon = false;
        foreach ($groupIds as $groupId) {
            foreach ($cal[$gkey($groupId, $week, $day)] ?? [] as [, $b]) {
                if ($b === tc_minutes('16:30')) {
                    $hasLateAfternoon = true;
                }
            }
        }
        if ($start === tc_minutes('16:30') && !$strict && $hasLateAfternoon) {
            return true;
        }
        return false;
    };

    $placeOne = function (array &$rows, array &$cal, array $groupIds, string $code, string $type, int $teacherId, string $room, bool $strict) use ($fits, $patterns, $push, $dayEnd): bool {
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($week = 1; $week <= 14; $week++) {
            for ($day = 0; $day < 6; $day++) {
                $candidates = $patterns[$day];
                $endNow = $dayEnd($cal, $groupIds, $week, $day);
                if ($endNow === tc_minutes('11:15')) {
                    $candidates[] = tc_minutes('11:15');
                }
                $candidates[] = tc_minutes('15:00');
                $candidates[] = tc_minutes('16:30');
                foreach (array_unique($candidates) as $start) {
                    $end = $start + 90;
                    if ($end > tc_minutes('18:00')) {
                        continue;
                    }
                    if (!$fits($cal, $groupIds, $teacherId, $room, $week, $day, $start, $end, $strict)) {
                        continue;
                    }
                    $load = 0;
                    foreach ($groupIds as $groupId) {
                        $load += count($cal["g{$groupId}|{$week}|{$day}"] ?? []);
                    }
                    $score = $load * 10 + ($start >= tc_minutes('15:00') ? 5 : 0) + $week;
                    if ($endNow === 0 && $start > tc_minutes('08:15')) {
                        $score -= 3;
                    }
                    if ($start === $endNow) {
                        $score -= 8;
                    }
                    if ($score < $bestScore) {
                        $bestScore = $score;
                        $best = [$week, $day, $start, $end];
                    }
                }
            }
        }
        if (!$best) {
            return false;
        }
        [$week, $day, $start, $end] = $best;
        $push($rows, $cal, [
            'week' => $week, 'day' => $day, 'start' => $start, 'end' => $end,
            'groups' => $groupIds, 'code' => $code, 'type' => $type, 'room' => $room,
            'lab' => '', 'teacher' => $teacherId, 'tp' => '', 'label' => '',
        ]);
        return true;
    };

    $morningCours = [
        [0, '09:45', 'UEF132'], [0, '11:15', 'UET111'],
        [1, '09:45', 'UEF111'], [1, '11:15', 'UEF121'],
        [2, '09:45', 'UEF122'], [2, '11:15', 'UET121'],
        [3, '09:45', 'UEF111'], [3, '11:15', 'UEF141'],
        [4, '09:45', 'UEF142'], [4, '11:15', 'UET122'],
        [5, '11:15', 'UET112'],
    ];
    $weeklyTd = [
        [0, '08:15', 'UEF132'],
        [2, '08:15', 'UEF131'],
        [4, '08:15', 'UEF111'],
    ];
    $cohortPlan = [
        'A' => ['G1', 'G2', 'Amphi 1', 0, 3, '15:00'],
        'B' => ['G3', 'G4', 'Amphi 2', 1, 0, '15:00'],
    ];
    for ($week = 1; $week <= 14; $week++) {
        foreach ($cohortPlan as [$labelA, $labelB, $room, $teacherIndex, $extraDay, $extraStart]) {
            $pair = [$groups[$labelA], $groups[$labelB]];
            foreach ($morningCours as [$day, $hm, $code]) {
                $start = tc_minutes($hm);
                $push($rows, $cal, [
                    'week' => $week, 'day' => $day, 'start' => $start, 'end' => $start + 90,
                    'groups' => $pair, 'code' => $code, 'type' => 'Cours', 'room' => $room,
                    'lab' => '', 'teacher' => (int) $teacherFor[$code][$teacherIndex], 'tp' => '', 'label' => '',
                ]);
            }
            $start = tc_minutes($extraStart);
            $push($rows, $cal, [
                'week' => $week, 'day' => $extraDay, 'start' => $start, 'end' => $start + 90,
                'groups' => $pair, 'code' => 'UET113', 'type' => 'Cours', 'room' => $room,
                'lab' => '', 'teacher' => (int) $teacherFor['UET113'][$teacherIndex], 'tp' => '', 'label' => '',
            ]);
        }
        foreach (['G1', 'G2', 'G3', 'G4'] as $label) {
            foreach ($weeklyTd as [$day, $hm, $code]) {
                $start = tc_minutes($hm);
                $push($rows, $cal, [
                    'week' => $week, 'day' => $day, 'start' => $start, 'end' => $start + 90,
                    'groups' => [$groups[$label]], 'code' => $code, 'type' => 'TD', 'room' => $roomsTd[$label],
                    'lab' => '', 'teacher' => $tdFor[$label], 'tp' => '', 'label' => $label,
                ]);
            }
        }
    }
    $slotFree = static function (array $cal, array $groupIds, int $teacherId, string $room, int $week, int $day, int $start, int $end) use ($free, $gkey): bool {
        if (!$free($cal, 'room|' . $room . '|' . $week . '|' . $day, $start, $end)) {
            return false;
        }
        if (!$free($cal, 'teacher|' . $teacherId . '|' . $week . '|' . $day, $start, $end)) {
            return false;
        }
        foreach ($groupIds as $groupId) {
            if (!$free($cal, $gkey($groupId, $week, $day), $start, $end)) {
                return false;
            }
        }
        return true;
    };
    $placeSpread = static function (array &$rows, array &$cal, array $groupIds, string $code, string $type, int $teacherId, string $room, int $need, array $candidates, bool $mustEnd) use ($push, $slotFree, &$unplaced): void {
        $target = tc_spread_weeks($need);
        foreach ($candidates as [$day, $hm]) {
            $start = tc_minutes($hm);
            $end = $start + 90;
            $chosen = [];
            foreach ($target as $week) {
                if ($slotFree($cal, $groupIds, $teacherId, $room, $week, $day, $start, $end)) {
                    $chosen[$week] = $week;
                }
            }
            for ($week = 14; count($chosen) < $need && $week >= 1; $week--) {
                if (!isset($chosen[$week]) && $slotFree($cal, $groupIds, $teacherId, $room, $week, $day, $start, $end)) {
                    $chosen[$week] = $week;
                }
            }
            if (count($chosen) < $need || ($mustEnd && !isset($chosen[14]))) {
                continue;
            }
            ksort($chosen);
            foreach ($chosen as $week) {
                $push($rows, $cal, [
                    'week' => $week, 'day' => $day, 'start' => $start, 'end' => $end,
                    'groups' => $groupIds, 'code' => $code, 'type' => $type, 'room' => $room,
                    'lab' => '', 'teacher' => $teacherId, 'tp' => '', 'label' => '',
                ]);
            }
            return;
        }
        $unplaced[] = $code . ' ' . $type . ' quinzaine non placée (' . $need . ')';
    };
    $coursCandidatesA = [[1, '08:15'], [3, '08:15'], [3, '16:30'], [1, '16:30']];
    $coursCandidatesB = [[1, '08:15'], [3, '08:15'], [0, '16:30'], [2, '16:30']];
    foreach (['UEF131' => [4, true], 'UEF122' => [6, false], 'UEF121' => [2, false]] as $code => [$need, $mustEnd]) {
        $placeSpread($rows, $cal, [$groups['G1'], $groups['G2']], $code, 'Cours', (int) $teacherFor[$code][0], 'Amphi 1', $need, $coursCandidatesA, $mustEnd);
        $placeSpread($rows, $cal, [$groups['G3'], $groups['G4']], $code, 'Cours', (int) $teacherFor[$code][1], 'Amphi 2', $need, $coursCandidatesB, $mustEnd);
    }
    $tdCandidates = [[5, '08:15'], [1, '08:15'], [3, '08:15'], [5, '09:45'], [3, '16:30'], [1, '16:30'], [0, '16:30'], [2, '16:30'], [4, '16:30']];
    foreach (['G1', 'G2', 'G3', 'G4'] as $label) {
        foreach (['UEF141' => [6, true], 'UEF142' => [6, true], 'UEF131' => [10, false], 'UEF111' => [4, false]] as $code => [$need, $mustEnd]) {
            $placeSpread($rows, $cal, [$groups[$label]], $code, 'TD', $tdFor[$label], $roomsTd[$label], $need, $tdCandidates, $mustEnd);
        }
    }

    $ids = array_values($groups);
    $pdo->exec('DELETE FROM emploi_temps WHERE id_groupe IN (' . implode(',', $ids) . ')');
    $insert = $pdo->prepare('INSERT INTO emploi_temps (id_groupe, id_mat, id_enseignant, jour, semaine, heure_debut, heure_fin, type_seance, salle) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $item) {
        $salle = $item['room'];
        if ($item['tp'] !== '') {
            $salle .= ' · ' . $item['tp'];
        }
        foreach ($item['groups'] as $groupId) {
            $insert->execute([
                $groupId,
                $subjectIds[$item['code']],
                $item['teacher'],
                $days[$item['day']],
                $item['week'],
                tc_hhmm($item['start']),
                tc_hhmm($item['end']),
                $item['type'],
                $salle,
            ]);
        }
    }

    return tc_report($pdo, $groups, $byCode, $tpSets, $unplaced, $names);
}

function tc_report(PDO $pdo, array $groups, array $byCode, array $tpSets, array $unplaced, array $teacherNames): array
{
    $labelOf = array_flip($groups);
    $sessions = $pdo->query('SELECT e.*, m.code_ecue, m.description_ecue, m.volume_cours, m.volume_td, m.volume_tp, m.volume_autres, en.prenom, en.nom FROM emploi_temps e JOIN matiere m ON m.id_mat = e.id_mat JOIN enseignant en ON en.id_enseignant = e.id_enseignant WHERE e.id_groupe IN (' . implode(',', array_values($groups)) . ')')->fetchAll();
    $hours = [];
    foreach (array_keys($byCode) as $code) {
        foreach ($labelOf as $label) {
            $hours[$code][$label] = ['Cours' => 0.0, 'TD' => 0.0, 'TP' => 0.0, 'Stage' => 0.0];
        }
    }
    $tpHours = [];
    $dayInfo = [];
    $primaryTp = ['G1' => 'TP1', 'G2' => 'TP4', 'G3' => 'TP7', 'G4' => 'TP10'];
    foreach ($sessions as $row) {
        $label = $labelOf[(int) $row['id_groupe']];
        $mins = (tc_minutes($row['heure_fin']) - tc_minutes($row['heure_debut'])) / 60;
        $tpName = '';
        if ($row['type_seance'] === 'TP' && preg_match('/TP\d+/', (string) $row['salle'], $match)) {
            $tpName = $match[0];
            $tpHours[$tpName] = ($tpHours[$tpName] ?? 0) + $mins;
        }
        $studentRow = $tpName === '' || $tpName === ($primaryTp[$label] ?? '');
        if ($studentRow && $row['type_seance'] !== 'TP') {
            $hours[$row['code_ecue']][$label][$row['type_seance']] += $mins;
        }
        $key = $label . '|' . $row['semaine'] . '|' . $row['jour'];
        $start = tc_minutes($row['heure_debut']);
        $end = tc_minutes($row['heure_fin']);
        if (!isset($dayInfo[$key])) {
            $dayInfo[$key] = ['start' => $start, 'end' => $end];
        } else {
            $dayInfo[$key]['start'] = min($dayInfo[$key]['start'], $start);
            $dayInfo[$key]['end'] = max($dayInfo[$key]['end'], $end);
        }
    }
    foreach ($primaryTp as $label => $tpName) {
        $hours['UEF131'][$label]['TP'] = (float) ($tpHours[$tpName] ?? 0);
    }
    $early = 0;
    $until18 = 0;
    $lateStart = 0;
    $daysCounted = count($dayInfo);
    foreach ($dayInfo as $info) {
        if ($info['end'] < tc_minutes('17:00')) {
            $early++;
        }
        if ($info['end'] >= tc_minutes('18:00')) {
            $until18++;
        }
        if ($info['start'] > tc_minutes('08:15')) {
            $lateStart++;
        }
    }
    $overlap = [];
    foreach ([['G1', 'G2'], ['G3', 'G4']] as [$a, $b]) {
        $common = 0.0;
        $onlyA = 0.0;
        $mapA = [];
        foreach ($sessions as $row) {
            if ($labelOf[(int) $row['id_groupe']] !== $a) {
                continue;
            }
            if ($row['type_seance'] === 'TP' && !str_contains((string) $row['salle'], $primaryTp[$a])) {
                continue;
            }
            $sig = $row['semaine'] . '|' . $row['jour'] . '|' . $row['heure_debut'] . '|' . $row['heure_fin'] . '|' . $row['code_ecue'] . '|' . $row['type_seance'];
            $mapA[$sig] = (tc_minutes($row['heure_fin']) - tc_minutes($row['heure_debut'])) / 60;
        }
        $mapB = [];
        foreach ($sessions as $row) {
            if ($labelOf[(int) $row['id_groupe']] !== $b) {
                continue;
            }
            if ($row['type_seance'] === 'TP' && !str_contains((string) $row['salle'], $primaryTp[$b])) {
                continue;
            }
            $sig = $row['semaine'] . '|' . $row['jour'] . '|' . $row['heure_debut'] . '|' . $row['heure_fin'] . '|' . $row['code_ecue'] . '|' . $row['type_seance'];
            $mapB[$sig] = (tc_minutes($row['heure_fin']) - tc_minutes($row['heure_debut'])) / 60;
        }
        foreach ($mapA as $sig => $h) {
            if (isset($mapB[$sig])) {
                $common += $h;
            } else {
                $onlyA += $h;
            }
        }
        $total = array_sum($mapA);
        $overlap[$a . '↔' . $b] = [
            'pct' => $total > 0 ? round($common / $total * 100, 1) : 0,
            'common' => $common,
            'different' => $onlyA,
        ];
    }
    return [
        'groups' => $groups,
        'hours' => $hours,
        'subjects' => $byCode,
        'tp_sets' => $tpSets,
        'tp_hours' => $tpHours,
        'unplaced' => $unplaced,
        'overlap' => $overlap,
        'days' => $daysCounted,
        'early' => $early,
        'until18' => $until18,
        'late_start' => $lateStart,
        'teachers' => $teacherNames,
        'sessions' => $sessions,
    ];
}

function tc_grid_slots(): array
{
    return [
        [tc_minutes('08:15'), tc_minutes('09:45')],
        [tc_minutes('09:45'), tc_minutes('11:15')],
        [tc_minutes('11:15'), tc_minutes('12:45')],
        [tc_minutes('14:00'), tc_minutes('15:00')],
        [tc_minutes('15:00'), tc_minutes('16:30')],
        [tc_minutes('16:30'), tc_minutes('18:00')],
    ];
}

function tc_render_timetable(array $byDay): string
{
    $days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $slots = tc_grid_slots();
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $label = static function (int $minutes): string {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    };
    $html = '<div class="schedule-grid-wrap"><table class="schedule-grid"><thead><tr><th>Jour</th>';
    foreach ($slots as [$slotStart, $slotEnd]) {
        $html .= '<th>' . $label($slotStart) . '–' . $label($slotEnd) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($days as $day) {
        $html .= '<tr><th>' . $escape($day) . '</th>';
        $coveredUntil = -1;
        foreach ($slots as $index => [$slotStart, $slotEnd]) {
            if ($coveredUntil > $index) {
                continue;
            }
            $items = [];
            foreach ($byDay[$day] ?? [] as $item) {
                $start = tc_minutes((string) $item['heure_debut']);
                if ($start >= $slotStart && $start < $slotEnd) {
                    $items[] = $item;
                }
            }
            if (!$items) {
                $html .= '<td></td>';
                continue;
            }
            $end = $slotEnd;
            foreach ($items as $item) {
                $end = max($end, tc_minutes((string) $item['heure_fin']));
            }
            $span = 1;
            for ($next = $index + 1; $next < count($slots); $next++) {
                if ($slots[$next][0] < $end) {
                    $span++;
                }
            }
            $coveredUntil = $index + $span;
            $html .= '<td' . ($span > 1 ? ' colspan="' . $span . '"' : '') . '>';
            foreach ($items as $item) {
                $type = strtolower((string) $item['type_seance']);
                $weeks = trim((string) ($item['week_list'] ?? ''));
                $weekNote = ($weeks !== '' && (int) ($item['weeks'] ?? 14) !== 14) ? 'Semaines ' . $weeks : '';
                $html .= '<div class="schedule-block schedule-' . $escape($type) . '"><strong>'
                    . $escape((string) $item['code_ecue']) . '</strong> '
                    . $escape((string) $item['description_ecue'])
                    . '<small>' . $escape(substr((string) $item['heure_debut'], 0, 5) . '–' . substr((string) $item['heure_fin'], 0, 5) . ' · ' . (string) $item['type_seance'])
                    . ($weekNote !== '' ? '<br>' . $escape($weekNote) : '')
                    . '<br>' . $escape((string) $item['salle'])
                    . '<br>' . $escape(trim((string) ($item['prenom'] ?? '') . ' ' . (string) ($item['nom'] ?? '')))
                    . '</small></div>';
            }
            $html .= '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}

function tc_export_html(array $report): string
{
    $days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $byGroup = [];
    $labelOf = array_flip($report['groups']);
    foreach ($report['sessions'] as $row) {
        $label = $labelOf[(int) $row['id_groupe']];
        $byGroup[$label][(int) $row['semaine']][$row['jour']][] = $row;
    }
    $notes = '';
    foreach (tc_notes() as $note) {
        $notes .= '<li>' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $cards = '';
    foreach ($report['overlap'] as $pair => $info) {
        $cards .= '<article><strong>' . $pair . '</strong><span>' . $info['pct'] . ' %</span><small>' . $info['common'] . ' h communes · ' . $info['different'] . ' h différentes</small></article>';
    }
    $body = '';
    foreach (['G1', 'G2', 'G3', 'G4'] as $label) {
        $bucket = [];
        foreach ($report['sessions'] as $row) {
            if ($labelOf[(int) $row['id_groupe']] !== $label) {
                continue;
            }
            $key = $row['jour'] . '|' . $row['heure_debut'] . '|' . $row['heure_fin'] . '|' . $row['code_ecue'] . '|' . $row['type_seance'] . '|' . $row['salle'];
            $bucket[$key]['row'] = $row;
            $bucket[$key]['weeks'][(int) $row['semaine']] = true;
        }
        $byDay = [];
        $extra = '';
        foreach ($bucket as $item) {
            $weeks = array_keys($item['weeks']);
            sort($weeks);
            $item['row']['weeks'] = count($weeks);
            $item['row']['week_list'] = implode(', ', $weeks);
            $byDay[$item['row']['jour']][] = $item['row'];
        }
        $body .= '<section class="sheet" data-group="' . $label . '"><h2>' . $label . ' — semaines 1 à 14</h2>';
        $body .= tc_render_timetable($byDay);
        if ($extra !== '') {
            $body .= '<h3>Séances qui ne tombent pas chaque semaine</h3><ul>' . $extra . '</ul>';
        }
        $body .= '</section>';
    }
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Emploi du temps — Tronc commun</title><style>
    body{font-family:Segoe UI,sans-serif;margin:24px;background:#f6f3ec;color:#1c1915} h1{margin-bottom:4px} .bar{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0} button{border:1px solid #c45c26;background:#fff;border-radius:999px;padding:6px 12px;cursor:pointer} button.on{background:#c45c26;color:#fff} .stats{display:flex;gap:12px;flex-wrap:wrap} article{background:#fff;border-radius:12px;padding:12px 16px;min-width:180px} article span{display:block;font-size:28px} .sheet{background:#fff;border-radius:12px;padding:16px;margin:16px 0} .schedule-grid{border-collapse:collapse;width:100%;table-layout:fixed} .schedule-grid th,.schedule-grid td{border:1px solid #d9d0c3;padding:8px;vertical-align:top} .schedule-grid thead th{background:#efe7da;text-align:center} .schedule-grid tbody th{width:7rem;text-align:left;background:#f3ecdf} .schedule-block{display:grid;gap:2px} .schedule-block small{color:#6b5f52}
    </style></head><body><h1>Emploi du temps — Tronc commun</h1><p>Un emploi par groupe, répété sur les 14 semaines.</p><ul>' . $notes . '</ul><div class="stats">' . $cards . '</div><div class="bar" id="filters"></div>' . $body . '<script>
    const sheets=[...document.querySelectorAll(".sheet")];
    const bar=document.getElementById("filters");
    let group="G1";
    function draw(){sheets.forEach(s=>s.hidden=s.dataset.group!==group); bar.querySelectorAll("button").forEach(b=>b.classList.toggle("on", b.dataset.group===group));}
    ["G1","G2","G3","G4"].forEach(g=>{const b=document.createElement("button");b.textContent=g;b.dataset.group=g;b.onclick=()=>{group=g;draw()};bar.appendChild(b);});
    draw();
    </script></body></html>';
}

function tc_export_pdf(array $report): string
{
    require_once __DIR__ . '/vendor/autoload.php';
    $html = tc_export_html($report);
    $html = str_replace('</head>', '<style>
      @page { size: A4 landscape; margin: 8mm; }
      body { margin: 0; background: #fff; font-size: 8px; }
      h1, body > p, body > ul, .stats, .bar { display: none !important; }
      .sheet { page-break-before: always; background: #fff; margin: 0; padding: 0; }
      .sheet:first-of-type { page-break-before: auto; }
      .sheet h2 { font-size: 14px; margin: 0 0 6px; }
      .schedule-grid { width: 100%; font-size: 8px; }
      .schedule-grid th, .schedule-grid td { padding: 3px 4px; }
      .schedule-block small { font-size: 7px; }
      .sheet h3, .sheet li { font-size: 8px; }
    </style></head>', $html);
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    return $dompdf->output();
}

function tc_xlsx(array $report): string
{
    $labelOf = array_flip($report['groups']);
    $sheets = [];
    $summary = [['Indicateur', 'Valeur'], ['Groupes TD', 'G1 G2 G3 G4'], ['Groupes TP', '12, rattachement provisoire'], ['Laboratoires', '2'], ['Journées avant 17:00', (string) $report['early']], ['Journées à 18:00', (string) $report['until18']], ['Débuts après 08:15', (string) $report['late_start']]];
    foreach ($report['overlap'] as $pair => $info) {
        $summary[] = ['Commun ' . $pair, $info['pct'] . ' %'];
    }
    foreach (tc_notes() as $note) {
        $summary[] = ['Note', $note];
    }
    foreach ($report['unplaced'] as $miss) {
        $summary[] = ['Non placé', $miss];
    }
    $sheets['Summary'] = $summary;
    $header = ['Semaine', 'Jour', 'Début', 'Fin', 'Type', 'Code', 'Matière', 'Salle', 'Enseignant'];
    foreach (['G1', 'G2', 'G3', 'G4'] as $label) {
        $grid = [$header];
        foreach ($report['sessions'] as $row) {
            if ($labelOf[(int) $row['id_groupe']] !== $label) {
                continue;
            }
            $grid[] = [$row['semaine'], $row['jour'], substr($row['heure_debut'], 0, 5), substr($row['heure_fin'], 0, 5), $row['type_seance'], $row['code_ecue'], $row['description_ecue'], $row['salle'], trim($row['prenom'] . ' ' . $row['nom'])];
        }
        $sheets[$label] = $grid;
    }
    $weeks = [$header];
    $weeks[0] = array_merge(['Groupe'], $header);
    foreach ($report['sessions'] as $row) {
        $weeks[] = [$labelOf[(int) $row['id_groupe']], $row['semaine'], $row['jour'], substr($row['heure_debut'], 0, 5), substr($row['heure_fin'], 0, 5), $row['type_seance'], $row['code_ecue'], $row['description_ecue'], $row['salle'], trim($row['prenom'] . ' ' . $row['nom'])];
    }
    $sheets['14 Weeks'] = $weeks;
    $volumes = [['Matière', 'Type', 'Demandé', 'G1', 'G2', 'G3', 'G4']];
    foreach ($report['subjects'] as $code => $subject) {
        foreach (['cours' => 'Cours', 'td' => 'TD', 'tp' => 'TP', 'stage' => 'Stage'] as $key => $type) {
            if ((float) $subject[$key] <= 0 && $key !== 'stage') {
                continue;
            }
            if ($key === 'stage' && (float) $subject['stage'] <= 0) {
                continue;
            }
            $line = [$subject['name'], $type, (string) $subject[$key]];
            foreach (['G1', 'G2', 'G3', 'G4'] as $label) {
                $line[] = (string) ($report['hours'][$code][$label][$type] ?? 0);
            }
            $volumes[] = $line;
        }
    }
    $sheets['Subjects & Volumes'] = $volumes;
    $tpSheet = [['Groupe TP', 'Rattachement provisoire', 'Matière', 'Demandé', 'Programmé', 'Écart']];
    foreach ($report['tp_sets'] as $label => $list) {
        foreach ($list as $tp) {
            $got = (float) ($report['tp_hours'][$tp] ?? 0);
            $tpSheet[] = [$tp, $label, 'Techniques infirmières 1', '45', (string) $got, (string) ($got - 45)];
        }
    }
    $sheets['TP Groups'] = $tpSheet;
    $labs = [['Semaine', 'Jour', 'Horaire', 'Labo', 'TP', 'Groupe', 'Enseignant']];
    foreach ($report['sessions'] as $row) {
        if ($row['type_seance'] !== 'TP') {
            continue;
        }
        preg_match('/TP\d+/', (string) $row['salle'], $match);
        $labs[] = [$row['semaine'], $row['jour'], substr($row['heure_debut'], 0, 5) . '–' . substr($row['heure_fin'], 0, 5), preg_replace('/ · TP\d+$/', '', (string) $row['salle']), $match[0] ?? '', $labelOf[(int) $row['id_groupe']], trim($row['prenom'] . ' ' . $row['nom'])];
    }
    $sheets['Laboratories'] = $labs;
    $rooms = [['Salle', 'Usage']];
    foreach (['Amphi 1' => 'Cours cohorte A, 50 places', 'Amphi 2' => 'Cours cohorte B, 50 places', 'Salle 1' => 'TD G1', 'Salle 2' => 'TD G2', 'Salle 3' => 'TD G3', 'Salle 4' => 'TD G4', 'Salle 5' => 'Libre', 'Labo TP Technique 1' => 'Techniques infirmières, un groupe à la fois', 'Labo TP Technique 2' => 'Techniques infirmières, un groupe à la fois', 'Stage' => 'Lieu non écrit sur la feuille'] as $room => $use) {
        $rooms[] = [$room, $use];
    }
    $sheets['Rooms'] = $rooms;
    $teachers = [['Enseignant', 'Source']];
    foreach ($report['teachers'] as $name) {
        $teachers[] = [$name, 'Compte déjà dans l\'application. Pas de nom sur la feuille.'];
    }
    $sheets['Teachers'] = $teachers;
    $sheets['Validation'] = $volumes;
    $conflicts = [['Contrôle', 'Résultat'], ['Deux TP dans le même laboratoire au même moment', 'Non : deux laboratoires, un groupe chacun'], ['Stage le matin', 'Aucun'], ['TP divisé entre les groupes', 'Non. Chaque groupe TP a les 45 h'], ['Code UEF142', 'Provisoire, illisible sur la photo']];
    $sheets['Conflicts'] = $conflicts;

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . implode('', array_map(static fn ($i) => '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, count($sheets)))) . '</Types>');
    $rels = '';
    $workbookSheets = '';
    $i = 1;
    foreach (array_keys($sheets) as $name) {
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        $workbookSheets .= '<sheet name="' . htmlspecialchars($name, ENT_QUOTES) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', tc_sheet_xml($sheets[$name]));
        $i++;
    }
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $workbookSheets . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="1"><xf/></cellXfs></styleSheet>');
    $zip->close();
    $binary = (string) file_get_contents($tmp);
    unlink($tmp);
    return $binary;
}

function tc_sheet_xml(array $grid): string
{
    $rows = '';
    $r = 1;
    foreach ($grid as $line) {
        $cells = '';
        $c = 1;
        foreach ($line as $value) {
            $col = '';
            $n = $c;
            while ($n > 0) {
                $n--;
                $col = chr(65 + ($n % 26)) . $col;
                $n = intdiv($n, 26);
            }
            $text = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $cells .= '<c r="' . $col . $r . '" t="inlineStr"><is><t>' . $text . '</t></is></c>';
            $c++;
        }
        $rows .= '<row r="' . $r . '">' . $cells . '</row>';
        $r++;
    }
    $lastCol = 'A';
    $width = max(1, count($grid[0] ?? []));
    $n = $width;
    $lastCol = '';
    while ($n > 0) {
        $n--;
        $lastCol = chr(65 + ($n % 26)) . $lastCol;
        $n = intdiv($n, 26);
    }
    $ref = 'A1:' . $lastCol . max(1, count($grid));
    return '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><autoFilter ref="' . $ref . '"/><sheetData>' . $rows . '</sheetData></worksheet>';
}

function tc_load_report(PDO $pdo): ?array
{
    $groups = [];
    $q = $pdo->query('SELECT id_groupe, numero FROM groupe WHERE semestre = "S1" AND type_groupe = "TD" AND numero BETWEEN 1 AND 4 ORDER BY numero');
    foreach ($q as $row) {
        $groups['G' . $row['numero']] = (int) $row['id_groupe'];
    }
    if (count($groups) < 4) {
        return null;
    }
    $count = (int) $pdo->query('SELECT COUNT(*) FROM emploi_temps WHERE id_groupe IN (' . implode(',', $groups) . ') AND type_seance = "Stage"')->fetchColumn();
    if ($count === 0) {
        return null;
    }
    $byCode = [];
    foreach (tc_subjects() as $subject) {
        $byCode[$subject['code']] = $subject;
    }
    $names = [];
    foreach ($pdo->query('SELECT id_enseignant, prenom, nom FROM enseignant') as $row) {
        $names[(int) $row['id_enseignant']] = trim($row['prenom'] . ' ' . $row['nom']);
    }
    return tc_report($pdo, $groups, $byCode, [
        'G1' => ['TP1', 'TP2', 'TP3'],
        'G2' => ['TP4', 'TP5', 'TP6'],
        'G3' => ['TP7', 'TP8', 'TP9'],
        'G4' => ['TP10', 'TP11', 'TP12'],
    ], [], $names);
}
