# Fixes applied

No database dump was taken. The only schema change is additive: `admin_user.auth_generation`, created on the next administrator request by the same `SHOW COLUMNS` / `ALTER TABLE` pattern already used for teacher and student accounts. Existing rows receive default `1`.

## Code

| File | Change |
| --- | --- |
| `application/config.php` | Security headers. Login throttle helpers. Administrator generation check. |
| `application/Auth/login.php` | CSRF, throttle, store `admin_auth_generation` at sign-in. |
| `application/Auth/connexion_etudiant.php` | CSRF and throttle. Database errors are no longer eligible to reach the page. |
| `application/Auth/creer_espace_etudiant.php` | Throttle around CIN lookup. Database errors logged, not shown. |
| `application/Auth/changer_mot_de_passe.php` | Increment `auth_generation` and refresh the current session. |
| `application/Admin/fix_utf8.php` | `require_admin()` before any query. Failures go to the error log. |
| `application/support/emploi_pdf.php` | PDF signature required. Control characters stripped from the stored display name. |
| `application/Documents/generate_word.php` | Generic client error. |
| `application/Documents/generate_reussite.php` | Generic client error. |
| `application/Documents/download_all_inscriptions.php` | Generic client error. |
| `application/Presence/generate_presence.php` | Generic client error. |
| `.htaccess` | Deny application, storage, database, config, docker, vendor, tests, scripts, templates, `.git`, compose, and `.env` when the project folder is the URL path. Disable indexes. |
| `storage/.htaccess` | Deny all if that directory is ever mapped into a site. |
| `docker-compose.yml` | MySQL host port bound to `127.0.0.1`. |
| `docker/start-web.sh` | `display_errors` and `expose_php` off for the next web-container start. |
| `tests/security_controls_cli.php` | Regression checks for the controls above. |

## Runtime

- `docker compose up -d --no-deps --force-recreate mysql` recreated `myapp_db`. `docker port myapp_db` reports `3306/tcp -> 127.0.0.1:3307`. The named volume was not removed.
- The web container was left running. Header and PHP settings in `start-web.sh` apply when that container is started again.

## Behaviour staff will notice

- Open administrator sessions are signed out once. Sign in again.
- Login pages must be reloaded so the CSRF field is in the form. A stale form returns “Requête invalide”.
- After eight wrong passwords for the same identity, or sixty failures from the same address, the form says to wait about fifteen minutes. A successful sign-in clears the identity counter.

## Follow-up

| File | Change |
| --- | --- |
| `application/Auth/creer_espace_etudiant.php` | Account creation requires the dossier birth date. Unknown CIN, wrong date, and an existing account return the same sentence. |
| `application/config.php` | `student_birth_matches()`. Unique partial index so a student can have only one pending attestation request. |
| `application/Scolarite/inscription_old.php` | `require_permission('students.edit')`, then redirect to `inscription.php`. The old update never runs. |
| `application/Scolarite/inscription.php` | `verify_csrf()` on both steps. |
| `application/Stage/stage_placements.php` | Year, status, host, service, and supervisor filters are bound parameters. Status must be one of `stage_statuses()`. |
| `application/pgsql.php` | `pgsql_rewrite_double_quoted_strings()` does not rewrite characters inside a single-quoted literal. |
| `application/Notes/gestion_notes.php` | New grades use the subject semester and the group must belong to that semester. Updates cannot move the grade to another student, subject, or semester. |
| `application/Presence/presence_etudiants.php` | Missing or unknown attendance values are rejected instead of stored as present. |
| `application/Stage/stage_docs.php` | Default storage is `storage/stage-documents`, which is outside `public/`. A path inside `public/` is refused. |
| `public/exports/.htaccess` | Deny direct download of the static timetable exports. |
| `application/support/landing_i18n.php` | Language cookie is `HttpOnly` and `Secure` on HTTPS. |
| `tests/pgsql_sql_cli.php`, `tests/security_controls_cli.php` | Cover the rewriter, signup birth check, legacy inscription redirect, bound stage filters, and inscription CSRF. |
