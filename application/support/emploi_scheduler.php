<?php
declare(strict_types=1);

require_once __DIR__ . '/emploi_smart.php';

/**
 * Constraint-aware timetable scheduler (pure PHP backtracking / greedy-MRV).
 * Hard constraints never silently skipped. Soft score only when hard OK.
 */

function emploi_scheduler_expand_requests(array $composantes, array $config): array
{
    $weeks = max(1, (int) ($config['semester_weeks'] ?? 14));
    $requests = [];
    foreach ($composantes as $comp) {
        $frequency = (string) ($comp['frequency'] ?? 'weekly');
        $perWeek = max(0.25, (float) ($comp['sessions_per_week'] ?? 1));
        $duration = max(15, (int) ($comp['duration_minutes'] ?? 90));
        $resourceType = (string) ($comp['preferred_resource_type'] ?? '');
        if ($resourceType === '') {
            $resourceType = emploi_smart_default_resource_type((string) $comp['type_seance']);
        }
        $base = [
            'id_composante' => (int) ($comp['id_composante'] ?? 0),
            'id_mat' => (int) $comp['id_mat'],
            'type_seance' => (string) $comp['type_seance'],
            'id_enseignant' => (int) $comp['id_enseignant'],
            'id_groupe' => (int) $comp['id_groupe'],
            'duration_minutes' => $duration,
            'lab_category' => (string) ($comp['lab_category'] ?? ''),
            'students_count' => (int) ($comp['students_count'] ?? 0),
            'preferred_resource_type' => $resourceType,
            'code_ecue' => (string) ($comp['code_ecue'] ?? ''),
        ];
        if ($frequency === 'fortnight_a') {
            $count = max(1, (int) ceil($perWeek));
            for ($i = 0; $i < $count; $i++) {
                $requests[] = $base + ['week_pattern' => 'A', 'semaine' => 1, 'request_key' => $base['id_composante'] . '-A-' . $i];
            }
            continue;
        }
        if ($frequency === 'fortnight_b') {
            $count = max(1, (int) ceil($perWeek));
            for ($i = 0; $i < $count; $i++) {
                $requests[] = $base + ['week_pattern' => 'B', 'semaine' => 2, 'request_key' => $base['id_composante'] . '-B-' . $i];
            }
            continue;
        }
        if ($frequency === 'biweekly') {
            $occurrences = max(1, (int) ceil(($perWeek * $weeks) / 2));
            for ($i = 0; $i < min($occurrences, (int) ceil($weeks / 2)); $i++) {
                $week = ($i * 2) + 1;
                $requests[] = $base + ['week_pattern' => 'specific', 'semaine' => $week, 'request_key' => $base['id_composante'] . '-W' . $week];
            }
            continue;
        }
        // weekly: place ceil(sessions_per_week) representative weekly slots (semaine=1 pattern weekly)
        $count = max(1, (int) ceil($perWeek));
        for ($i = 0; $i < $count; $i++) {
            $requests[] = $base + ['week_pattern' => 'weekly', 'semaine' => 1, 'request_key' => $base['id_composante'] . '-W-' . $i];
        }
    }
    return $requests;
}

function emploi_scheduler_candidate_starts(array $config, int $duration): array
{
    $slot = max(5, (int) ($config['slot_minutes'] ?? 15));
    $starts = [];
    $windows = [
        [emploi_smart_minutes((string) $config['morning_start']), emploi_smart_minutes((string) $config['morning_end'])],
        [emploi_smart_minutes((string) $config['afternoon_start']), emploi_smart_minutes((string) $config['afternoon_end'])],
    ];
    $breakStart = emploi_smart_minutes((string) $config['break_start']);
    $breakEnd = emploi_smart_minutes((string) $config['break_end']);
    foreach ($windows as [$winStart, $winEnd]) {
        for ($t = $winStart; $t + $duration <= $winEnd; $t += $slot) {
            if (emploi_smart_overlaps($t, $t + $duration, $breakStart, $breakEnd)) {
                continue;
            }
            $starts[] = $t;
        }
    }
    return array_values(array_unique($starts));
}

function emploi_scheduler_compatible_resources(array $request, array $resources): array
{
    $type = (string) $request['preferred_resource_type'];
    if ($type === '') {
        $type = emploi_smart_default_resource_type((string) ($request['type_seance'] ?? 'Cours'));
    }
    $lab = trim((string) ($request['lab_category'] ?? ''));
    $students = max(0, (int) ($request['students_count'] ?? 0));
    // Capacity 0 in request means "unknown" — do not block matching on headcount.
    $needCapacity = $students > 0 ? $students : 0;
    $out = [];
    foreach ($resources as $res) {
        if ((int) ($res['active'] ?? 0) !== 1 && !in_array((string) ($res['active'] ?? ''), ['1', 't', 'true'], true)) {
            continue;
        }
        if ((string) $res['type_ressource'] !== $type) {
            continue;
        }
        if ($type === 'labo' && $lab !== '') {
            $resLab = trim((string) ($res['lab_category'] ?? ''));
            // Empty lab category on the room = general lab, accept any TP category.
            if ($resLab !== '' && strcasecmp($resLab, $lab) !== 0) {
                continue;
            }
        }
        if ($needCapacity > 0 && (int) $res['capacite'] < $needCapacity) {
            continue;
        }
        $out[] = $res;
    }
    usort($out, static function (array $a, array $b): int {
        $cmp = ((int) $a['capacite']) <=> ((int) $b['capacite']);
        return $cmp !== 0 ? $cmp : ((int) $a['id_ressource']) <=> ((int) $b['id_ressource']);
    });
    return $out;
}

function emploi_scheduler_day_minutes_for(array $sessions, string $role, int $id, string $day, string $pattern, int $week): int
{
    $total = 0;
    foreach ($sessions as $session) {
        if ((string) $session['jour'] !== $day) {
            continue;
        }
        $key = $role === 'teacher' ? 'id_enseignant' : 'id_groupe';
        if ((int) $session[$key] !== $id) {
            continue;
        }
        $sPattern = (string) ($session['week_pattern'] ?? 'weekly');
        $sWeek = (int) ($session['semaine'] ?? 1);
        if ($pattern === 'weekly' || $sPattern === 'weekly' || ($pattern === $sPattern) || $week === $sWeek) {
            $total += emploi_smart_minutes((string) $session['heure_fin']) - emploi_smart_minutes((string) $session['heure_debut']);
        }
    }
    return $total;
}

function emploi_scheduler_soft_score(array $candidate, array $placed, array $config): float
{
    $weights = (array) ($config['soft_weights'] ?? emploi_smart_default_weights());
    $score = 100.0;
    $day = (string) $candidate['jour'];
    $start = emploi_smart_minutes((string) $candidate['heure_debut']);
    $end = emploi_smart_minutes((string) $candidate['heure_fin']);
    $groupLoad = emploi_scheduler_day_minutes_for($placed, 'group', (int) $candidate['id_groupe'], $day, (string) $candidate['week_pattern'], (int) $candidate['semaine']);
    $teacherLoad = emploi_scheduler_day_minutes_for($placed, 'teacher', (int) $candidate['id_enseignant'], $day, (string) $candidate['week_pattern'], (int) $candidate['semaine']);
    $maxGroup = (float) ($config['max_group_hours_day'] ?? 8) * 60;
    $maxTeacher = (float) ($config['max_teacher_hours_day'] ?? 8) * 60;
    if ($groupLoad + ($end - $start) > $maxGroup) {
        $score -= 8 * (float) ($weights['avoid_student_overload'] ?? 4);
    }
    if ($teacherLoad + ($end - $start) > $maxTeacher) {
        $score -= 6 * (float) ($weights['avoid_teacher_gaps'] ?? 2);
    }
    if ($start < emploi_smart_minutes('09:00') || $start >= emploi_smart_minutes('16:30')) {
        $score -= 2 * (float) ($weights['avoid_early_late'] ?? 1);
    }
    // Prefer filling near existing group sessions (reduce gaps)
    $nearestGap = null;
    foreach ($placed as $session) {
        if ((int) $session['id_groupe'] !== (int) $candidate['id_groupe'] || (string) $session['jour'] !== $day) {
            continue;
        }
        $sEnd = emploi_smart_minutes((string) $session['heure_fin']);
        $sStart = emploi_smart_minutes((string) $session['heure_debut']);
        $gapBefore = $start - $sEnd;
        $gapAfter = $sStart - $end;
        foreach ([$gapBefore, $gapAfter] as $gap) {
            if ($gap >= 0 && ($nearestGap === null || $gap < $nearestGap)) {
                $nearestGap = $gap;
            }
        }
    }
    if ($nearestGap !== null) {
        if ($nearestGap === 0) {
            $score += 3 * (float) ($weights['prefer_consecutive'] ?? 2);
        } elseif ($nearestGap > 90) {
            $score -= 2 * (float) ($weights['avoid_gaps'] ?? 2);
        }
    }
    // Even spread: fewer sessions already on this day is better
    $sameDay = 0;
    foreach ($placed as $session) {
        if ((int) $session['id_groupe'] === (int) $candidate['id_groupe'] && (string) $session['jour'] === $day) {
            $sameDay++;
        }
    }
    $score -= $sameDay * (float) ($weights['even_spread'] ?? 3);
    return $score;
}

function emploi_scheduler_suggest_for_failure(array $request, string $reason): array
{
    $suggestions = [];
    if (str_contains($reason, 'ressource') || str_contains($reason, 'capacité') || str_contains($reason, 'laboratoire')) {
        $suggestions[] = 'Ajoutez une autre salle, amphithéâtre ou laboratoire compatible.';
        $suggestions[] = 'Réduisez l’effectif du sous-groupe ou changez de catégorie de laboratoire.';
    }
    if (str_contains($reason, 'enseignant')) {
        $suggestions[] = 'Élargissez la disponibilité de l’enseignant ou assignez un autre enseignant.';
        $suggestions[] = 'Diminuez le nombre de séances demandées pour cette composante.';
    }
    if (str_contains($reason, 'groupe')) {
        $suggestions[] = 'Répartissez les séances sur plus de jours ou réduisez la charge quotidienne.';
    }
    if (str_contains($reason, 'créneau') || str_contains($reason, 'période')) {
        $suggestions[] = 'Allongez les plages matin/après-midi ou réduisez la durée de séance.';
        $suggestions[] = 'Augmentez le nombre de jours ouvrés.';
    }
    if ($suggestions === []) {
        $suggestions[] = 'Assouplissez une contrainte académique (fréquence, durée) si autorisé.';
        $suggestions[] = 'Régénérez après avoir verrouillé les séances déjà valides.';
    }
    return $suggestions;
}

/**
 * @param array $composantes DB rows (with code_ecue optional)
 * @param array $resources active resources
 * @param array $config scheduling config
 * @param array $lockedSessions already placed locked sessions
 * @return array{scheduled: array, unscheduled: array, quality_score: float, requested_count: int, scheduled_count: int, unscheduled_count: int, total_hours: float, stats: array, warnings: array}
 */
function emploi_scheduler_generate(array $composantes, array $resources, array $config, array $lockedSessions = []): array
{
    $requests = emploi_scheduler_expand_requests($composantes, $config);
    $days = array_values((array) ($config['working_days'] ?? ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi']));
    $placed = [];
    foreach ($lockedSessions as $locked) {
        $placed[] = [
            'id_composante' => $locked['id_composante'] ?? null,
            'id_groupe' => (int) $locked['id_groupe'],
            'id_mat' => (int) $locked['id_mat'],
            'id_enseignant' => (int) $locked['id_enseignant'],
            'id_ressource' => (int) $locked['id_ressource'],
            'type_seance' => (string) $locked['type_seance'],
            'jour' => (string) $locked['jour'],
            'semaine' => (int) ($locked['semaine'] ?? 1),
            'week_pattern' => (string) ($locked['week_pattern'] ?? 'weekly'),
            'heure_debut' => substr(emploi_smart_time_norm((string) $locked['heure_debut']), 0, 5),
            'heure_fin' => substr(emploi_smart_time_norm((string) $locked['heure_fin']), 0, 5),
            'locked' => 1,
        ];
    }

    // MRV: scarcer resources / longer sessions first
    usort($requests, static function (array $a, array $b) use ($resources): int {
        $ra = count(emploi_scheduler_compatible_resources($a, $resources));
        $rb = count(emploi_scheduler_compatible_resources($b, $resources));
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        if ($a['duration_minutes'] !== $b['duration_minutes']) {
            return $b['duration_minutes'] <=> $a['duration_minutes'];
        }
        return strcmp((string) $a['request_key'], (string) $b['request_key']);
    });

    $scheduled = [];
    $unscheduled = [];
    $warnings = [];

    foreach ($requests as $request) {
        $compatible = emploi_scheduler_compatible_resources($request, $resources);
        if ($compatible === []) {
            $reason = 'Aucune ressource compatible libre (type/capacité/catégorie).';
            $unscheduled[] = $request + [
                'reason' => $reason,
                'required_resource' => $request['preferred_resource_type'] . ($request['lab_category'] !== '' ? '/' . $request['lab_category'] : ''),
                'suggestions' => emploi_scheduler_suggest_for_failure($request, $reason),
            ];
            continue;
        }
        $starts = emploi_scheduler_candidate_starts($config, (int) $request['duration_minutes']);
        if ($starts === []) {
            $reason = 'Aucun créneau ne contient la durée demandée dans les périodes autorisées.';
            $unscheduled[] = $request + [
                'reason' => $reason,
                'required_resource' => $request['preferred_resource_type'],
                'suggestions' => emploi_scheduler_suggest_for_failure($request, $reason),
            ];
            continue;
        }

        $best = null;
        $bestScore = -INF;
        foreach ($days as $dayIndex => $day) {
            foreach ($starts as $start) {
                $end = $start + (int) $request['duration_minutes'];
                foreach ($compatible as $res) {
                    $candidate = [
                        'id_composante' => $request['id_composante'],
                        'id_groupe' => $request['id_groupe'],
                        'id_mat' => $request['id_mat'],
                        'id_enseignant' => $request['id_enseignant'],
                        'id_ressource' => (int) $res['id_ressource'],
                        'type_seance' => $request['type_seance'],
                        'jour' => $day,
                        'semaine' => (int) $request['semaine'],
                        'week_pattern' => (string) $request['week_pattern'],
                        'heure_debut' => emploi_smart_hhmm($start),
                        'heure_fin' => emploi_smart_hhmm($end),
                        'locked' => 0,
                    ];
                    $conflict = emploi_smart_session_conflict($candidate, $placed);
                    if ($conflict) {
                        continue;
                    }
                    // Max daily hours hard-ish: treat as hard when exceeded
                    $groupLoad = emploi_scheduler_day_minutes_for($placed, 'group', $request['id_groupe'], $day, (string) $request['week_pattern'], (int) $request['semaine']);
                    $teacherLoad = emploi_scheduler_day_minutes_for($placed, 'teacher', $request['id_enseignant'], $day, (string) $request['week_pattern'], (int) $request['semaine']);
                    $dur = (int) $request['duration_minutes'];
                    if ($groupLoad + $dur > (float) ($config['max_group_hours_day'] ?? 8) * 60 + 0.01) {
                        continue;
                    }
                    if ($teacherLoad + $dur > (float) ($config['max_teacher_hours_day'] ?? 8) * 60 + 0.01) {
                        continue;
                    }
                    $score = emploi_scheduler_soft_score($candidate, $placed, $config);
                    $score -= $dayIndex * 0.01; // slight preference for earlier weekdays (deterministic)
                    $score -= ((int) $res['id_ressource']) * 0.0001;
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $candidate;
                    }
                }
            }
        }

        if ($best === null) {
            $reason = 'Aucun créneau sans conflit (enseignant, groupe ou ressource exclusive).';
            $unscheduled[] = $request + [
                'reason' => $reason,
                'required_resource' => $request['preferred_resource_type'] . ($request['lab_category'] !== '' ? '/' . $request['lab_category'] : ''),
                'suggestions' => emploi_scheduler_suggest_for_failure($request, $reason),
            ];
            continue;
        }
        $placed[] = $best;
        $scheduled[] = $best;
    }

    $totalMinutes = 0;
    foreach ($scheduled as $s) {
        $totalMinutes += emploi_smart_minutes((string) $s['heure_fin']) - emploi_smart_minutes((string) $s['heure_debut']);
    }
    $quality = 0.0;
    if ($unscheduled === [] && $scheduled !== []) {
        $quality = 70.0;
        foreach ($scheduled as $s) {
            $quality += max(0, emploi_scheduler_soft_score($s, array_filter($placed, static fn($p) => $p !== $s), $config)) / max(1, count($scheduled)) * 0.3;
        }
        $quality = min(100, round($quality, 2));
    } elseif ($scheduled !== []) {
        $quality = round(40 * (count($scheduled) / max(1, count($requests))), 2);
        $warnings[] = 'Des séances n’ont pas pu être planifiées : le score qualité n’est pas un certificat d’absence de conflit.';
    }

    $resourceUsage = [];
    foreach ($scheduled as $s) {
        $rid = (int) $s['id_ressource'];
        $resourceUsage[$rid] = ($resourceUsage[$rid] ?? 0) + 1;
    }

    return [
        'scheduled' => $scheduled,
        'unscheduled' => $unscheduled,
        'quality_score' => $quality,
        'requested_count' => count($requests),
        'scheduled_count' => count($scheduled),
        'unscheduled_count' => count($unscheduled),
        'total_hours' => round($totalMinutes / 60, 2),
        'stats' => [
            'resource_usage' => $resourceUsage,
            'days' => $days,
            'locked_preserved' => count($lockedSessions),
        ],
        'warnings' => $warnings,
    ];
}

/**
 * Run generation for a stored version: preserves locked sessions, replaces unlocked.
 */
function emploi_scheduler_run_for_version(int $versionId, string $createdBy = '', bool $onlyUnscheduled = false): array
{
    emploi_smart_ensure();
    $version = emploi_smart_get_version($versionId);
    if (!$version) {
        throw new InvalidArgumentException('Version introuvable.');
    }
    if (in_array((string) $version['status'], ['published', 'archived'], true)) {
        throw new InvalidArgumentException('Régénération refusée sur une version publiée ou archivée. Cliquez d’abord sur « Brouillon », puis Générer.');
    }
    $config = (array) ($version['config'] ?: emploi_smart_get_config());
    $composantes = emploi_smart_list_composantes($versionId);
    if ($composantes === []) {
        throw new InvalidArgumentException('Aucune composante d’enseignement configurée pour cette version.');
    }
    $resources = emploi_smart_list_resources(true);
    $existing = emploi_smart_list_sessions($versionId);
    $locked = array_values(array_filter($existing, static fn(array $s): bool => !empty($s['locked'])));

    if ($onlyUnscheduled) {
        $latest = emploi_smart_latest_run($versionId);
        $unscheduledIds = [];
        foreach ((array) ($latest['unscheduled'] ?? []) as $u) {
            if (!empty($u['id_composante'])) {
                $unscheduledIds[(int) $u['id_composante']] = true;
            }
        }
        if ($unscheduledIds) {
            $composantes = array_values(array_filter(
                $composantes,
                static fn(array $c): bool => isset($unscheduledIds[(int) $c['id_composante']])
            ));
        }
        $locked = $existing; // keep all current sessions as frozen
    }

    $result = emploi_scheduler_generate($composantes, $resources, $config, $locked);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($onlyUnscheduled) {
            // only append newly scheduled; do not clear
            emploi_smart_insert_sessions($versionId, $result['scheduled']);
        } else {
            emploi_smart_clear_unlocked_sessions($versionId);
            emploi_smart_insert_sessions($versionId, $result['scheduled']);
        }
        $runId = emploi_smart_save_generation_run($versionId, $result, $createdBy);
        emploi_smart_update_version($versionId, [
            'quality_score' => $result['quality_score'],
            'status' => 'draft',
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    $result['id_run'] = $runId ?? 0;
    return $result;
}
