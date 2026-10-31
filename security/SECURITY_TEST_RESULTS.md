# Security test results

Command:

```text
C:\xampp\php\php.exe tests\security_controls_cli.php
```

Result: `SECURITY CONTROLS PASSED` (exit 0).

| Check | Result |
| --- | --- |
| `fix_utf8.php` calls `require_admin()` before `UPDATE` | pass |
| Repair script does not echo exception text | pass |
| Inscription and presence certificate handlers do not echo exception text | pass |
| Root `.htaccess` forbids the application tree | pass |
| Staff login calls `verify_csrf()` and renders a token | pass |
| Student login calls `verify_csrf()` and renders a token | pass |
| Both logins call `auth_rate_limited` | pass |
| Eight failures lock one identity bucket | pass |
| Clearing that bucket removes the lock | pass |
| Plain text is rejected by `emploi_pdf_signature_ok()` | pass |
| Formula parser rejects a statement separator | pass |
| Formula parser still evaluates a numeric variable | pass |
| `X-Content-Type-Options: nosniff` is set from `config.php` | pass |
| Administrator sessions reference `admin_auth_generation` | pass |
| Student signup calls `student_birth_matches`, and a wrong or empty date fails | pass |
| Legacy inscription exits before its `UPDATE` | pass |
| Stage placement filters do not call `db()->quote()` | pass |
| A double quote inside a single-quoted literal survives `pgsql_sql()` | pass |
| Current inscription POST calls `verify_csrf()` | pass |

`php -l` reported no syntax errors on each edited PHP file.

## Not run

- OWASP ZAP, Burp, Semgrep, Trivy, Gitleaks, Composer audit, npm audit, PHPStan, PHPUnit. Those tools were not invoked and were not installed.
- Authenticated browser sessions as student, teacher, finance, stage, and administrator. Doing that against the live database would have read real academic records, which this audit avoided.
- HTTP fetch of `/.htaccess` deny rules. XAMPP on port 80 refused connections during the audit.
- Web-container restart, so the new `display_errors` setting was not observed in a live response.

## MySQL binding

After recreate, `docker port myapp_db` returned `3306/tcp -> 127.0.0.1:3307`.
