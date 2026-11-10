<?php
declare(strict_types=1);

/**
 * Security policy: idle session timeout + password rules (no 2FA).
 */

function security_policy_defaults(): array
{
    return [
        'session_idle_minutes' => 60,
        'password_min_length' => 8,
        'password_require_upper' => false,
        'password_require_lower' => false,
        'password_require_digit' => false,
        'password_require_special' => false,
    ];
}

function security_policy_ensure(): void
{
    institution_ensure();
    $defaults = security_policy_defaults();
    $insert = db()->prepare('INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
    $insert->execute(['security_session_idle_minutes', (string) $defaults['session_idle_minutes']]);
    $insert->execute(['security_password_min_length', (string) $defaults['password_min_length']]);
    $insert->execute(['security_password_require_upper', $defaults['password_require_upper'] ? '1' : '0']);
    $insert->execute(['security_password_require_lower', $defaults['password_require_lower'] ? '1' : '0']);
    $insert->execute(['security_password_require_digit', $defaults['password_require_digit'] ? '1' : '0']);
    $insert->execute(['security_password_require_special', $defaults['password_require_special'] ? '1' : '0']);
}

function security_policy(): array
{
    security_policy_ensure();
    $defaults = security_policy_defaults();

    $rawIdle = trim(institution_setting('security_session_idle_minutes'));
    $idle = $rawIdle === '' ? (int) $defaults['session_idle_minutes'] : (int) $rawIdle;
    if ($idle < 0) {
        $idle = 0;
    }
    if ($idle > 1440) {
        $idle = 1440;
    }

    $rawMin = trim(institution_setting('security_password_min_length'));
    $minLength = $rawMin === '' ? (int) $defaults['password_min_length'] : (int) $rawMin;
    if ($minLength < 6) {
        $minLength = 6;
    }
    if ($minLength > 72) {
        $minLength = 72;
    }

    return [
        'session_idle_minutes' => $idle,
        'password_min_length' => $minLength,
        'password_require_upper' => trim(institution_setting('security_password_require_upper')) === '1',
        'password_require_lower' => trim(institution_setting('security_password_require_lower')) === '1',
        'password_require_digit' => trim(institution_setting('security_password_require_digit')) === '1',
        'password_require_special' => trim(institution_setting('security_password_require_special')) === '1',
    ];
}

function security_save_policy(
    int $idleMinutes,
    int $minLength,
    bool $requireUpper,
    bool $requireLower,
    bool $requireDigit,
    bool $requireSpecial
): void {
    if ($idleMinutes < 0 || $idleMinutes > 1440) {
        throw new InvalidArgumentException('La durée d’inactivité doit être entre 0 et 1440 minutes.');
    }
    if ($minLength < 6 || $minLength > 72) {
        throw new InvalidArgumentException('La longueur minimale du mot de passe doit être entre 6 et 72.');
    }

    security_policy_ensure();
    institution_setting_put('security_session_idle_minutes', (string) $idleMinutes);
    institution_setting_put('security_password_min_length', (string) $minLength);
    institution_setting_put('security_password_require_upper', $requireUpper ? '1' : '0');
    institution_setting_put('security_password_require_lower', $requireLower ? '1' : '0');
    institution_setting_put('security_password_require_digit', $requireDigit ? '1' : '0');
    institution_setting_put('security_password_require_special', $requireSpecial ? '1' : '0');
    academic_policy_note('securite_session', 'Idle ' . $idleMinutes . ' min · MDP ≥' . $minLength);
}

function security_session_idle_minutes(): int
{
    return (int) security_policy()['session_idle_minutes'];
}

function password_policy_min_length(): int
{
    return (int) security_policy()['password_min_length'];
}

function password_policy_hint(): string
{
    $policy = security_policy();
    $parts = ['au moins ' . (int) $policy['password_min_length'] . ' caractères'];
    if (!empty($policy['password_require_upper'])) {
        $parts[] = 'une majuscule';
    }
    if (!empty($policy['password_require_lower'])) {
        $parts[] = 'une minuscule';
    }
    if (!empty($policy['password_require_digit'])) {
        $parts[] = 'un chiffre';
    }
    if (!empty($policy['password_require_special'])) {
        $parts[] = 'un caractère spécial';
    }
    return 'Le mot de passe doit contenir ' . implode(', ', $parts) . '.';
}

/**
 * @return string|null Error message, or null if valid.
 */
function password_policy_validate(string $password): ?string
{
    $policy = security_policy();
    $min = (int) $policy['password_min_length'];
    $length = strlen($password);

    if ($length < $min) {
        return 'Le mot de passe doit contenir au moins ' . $min . ' caractères.';
    }
    if ($length > 72) {
        return 'Le mot de passe ne peut pas dépasser 72 caractères.';
    }
    if (!empty($policy['password_require_upper']) && !preg_match('/[A-ZÀ-ÖØ-Þ]/u', $password)) {
        return 'Le mot de passe doit contenir au moins une majuscule.';
    }
    if (!empty($policy['password_require_lower']) && !preg_match('/[a-zà-öø-ÿ]/u', $password)) {
        return 'Le mot de passe doit contenir au moins une minuscule.';
    }
    if (!empty($policy['password_require_digit']) && !preg_match('/[0-9]/', $password)) {
        return 'Le mot de passe doit contenir au moins un chiffre.';
    }
    if (!empty($policy['password_require_special']) && !preg_match('/[^A-Za-z0-9À-ÖØ-öø-ÿ]/u', $password)) {
        return 'Le mot de passe doit contenir au moins un caractère spécial.';
    }

    return null;
}

/**
 * @return string|null Error message, or null if valid.
 */
function password_policy_check_new(string $password, string $confirm): ?string
{
    if ($password !== $confirm) {
        return 'Les deux mots de passe ne correspondent pas.';
    }
    return password_policy_validate($password);
}

function app_touch_session_activity(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['last_activity'] = time();
    }
}

function app_enforce_session_idle(): void
{
    if (!logged_in()) {
        return;
    }

    $minutes = security_session_idle_minutes();
    $now = time();
    $last = (int) ($_SESSION['last_activity'] ?? 0);

    if ($minutes > 0 && $last > 0 && ($now - $last) > ($minutes * 60)) {
        $student = is_student();
        $login = app_logout_current($student);
        header('Location: ' . $login . '?error=session_expired');
        exit;
    }

    app_touch_session_activity();
}
