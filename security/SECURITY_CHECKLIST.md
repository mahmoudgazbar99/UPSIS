# Security checklist

Use this after a change to auth, files, grades, finance, or deployment.

## Authentication

- [ ] Staff login and student login send `csrf_token` and call `verify_csrf()`.
- [ ] Failed attempts call `auth_rate_fail()` and a success calls `auth_rate_clear()`.
- [ ] `session_regenerate_id(true)` runs before the role is stored.
- [ ] New passwords use `password_hash()`. Verification uses `password_verify()`.
- [ ] Opening a student portal requires the CIN and the birth date on the dossier. Do not create the account from the CIN alone.
- [ ] Teacher, student, finance, scolarité, stage, and administrator sessions store and compare an `auth_generation`.
- [ ] `validate_current_account_status()` still runs from `application/config.php`.

## Authorization

- [ ] Every new script in `application/routes.php` calls a `require_*` or `require_permission()` before reading or writing.
- [ ] A teacher mutation checks `current_teacher_id()` against ownership or `enseignant_affectation`.
- [ ] A student read checks `$_SESSION['id_etudiant']` against the row, not a client-supplied id alone.
- [ ] Maintenance scripts are not reachable anonymously. `fix_utf8.php` must keep `require_admin()` above any `UPDATE`.

## Input and output

- [ ] SQL values are bound. Do not concatenate `PDO::quote()` into a statement that later passes through `pgsql_sql()`.
- [ ] Identifiers are not taken from the request.
- [ ] HTML uses `e()`.
- [ ] Catch blocks that face the browser do not echo `getMessage()` for driver exceptions.
- [ ] Grade and fee formulas stay inside `formula_evaluate()`.

## Files

- [ ] Uploads use `is_uploaded_file()` and a content check, not the extension alone.
- [ ] PDFs require `%PDF-` and `application/pdf`.
- [ ] Stored names are generated. Client names are display labels only.
- [ ] `readfile` targets stay under the storage root after `realpath`.
- [ ] Downloads send `X-Content-Type-Options: nosniff`.

## Deployment

- [ ] Docker document root remains `public/`. The parent directory stays denied.
- [ ] Postgres and MySQL published ports stay on `127.0.0.1`.
- [ ] `/.htaccess` still forbids `application/`, `storage/`, `database/`, and `docker-compose.yml`.
- [ ] `display_errors` and `expose_php` stay off after the web container is recreated.
- [ ] Database passwords are not copied into new tracked files.

## Regression

- [ ] `C:\xampp\php\php.exe tests\security_controls_cli.php` prints `SECURITY CONTROLS PASSED`.
