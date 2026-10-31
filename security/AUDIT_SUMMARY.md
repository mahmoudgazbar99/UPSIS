# Security audit summary

Date: 2026-10-31  
Application: UPSIS university ERP (`C:\xampp\htdocs\webapp`)  
Scope: this repository and its local Docker/XAMPP deployment only. No third-party systems were attacked. No real student records were read or modified.

## Method

The review traced routes, role gates, SQL construction, file upload and download handlers, session cookies, CSRF checks, and the Docker/XAMPP layout. Findings below are ones confirmed in code. Live authenticated attacks against real academic records were not run.

Five specialist reviews were started in parallel for authentication, injection, file handling, business logic, and infrastructure. This report records the issues confirmed and fixed by the coordinating pass. Automated scanners (ZAP, Semgrep, Trivy, Gitleaks, Composer audit) were not available in this environment and were not installed.

## Result

Confirmed issues that were fixed:

| ID | Severity | Issue | Status |
| --- | --- | --- | --- |
| AUTH-01 | High | `fix_utf8.php` rewrote name columns with no login | Fixed |
| AUTH-02 | Medium | Administrator sessions stayed valid after a password change | Fixed |
| AUTH-03 | Medium | Staff and student logins had no attempt limit | Fixed |
| AUTH-04 | Medium | Staff and student login forms had no CSRF token | Fixed |
| FILE-01 | Medium | Timetable upload accepted a non-PDF when the name ended in `.pdf` | Fixed |
| INJ-01 | Medium | Certificate endpoints printed raw exception text | Fixed |
| INFRA-01 | High | XAMPP document root is `C:/xampp/htdocs`, so the project tree was web-reachable | Fixed in `.htaccess` |
| INFRA-02 | Medium | MySQL was published on all interfaces at port 3307 | Fixed and recreated |
| INFRA-03 | Low | PHP error display was not forced off in the web container | Fixed in startup script; applies on next web-container start |

Controls that already held, from code inspection:

- Course PDFs are checked for `%PDF-` and `application/pdf`, stored outside `public/` under random names, and downloaded only after an ownership check.
- Stage document downloads check the placement owner, reject paths outside the storage root, and compare a stored hash before sending the file.
- Grade saves require CSRF. A teacher must be assigned to the subject and group, and cannot update an existing grade.
- Passwords for new accounts use `password_hash`. Login calls `session_regenerate_id(true)`. Cookies are `HttpOnly` and `SameSite=Lax`, and `Secure` when the request is HTTPS.
- PDO is created with emulated prepares disabled.
- Grade formulas go through a character whitelist and a parser. They are not passed to `eval`.
- LibreOffice conversion passes paths through `escapeshellarg`.
- The Docker web root is `public/`, and the parent directory is denied. PostgreSQL is bound to `127.0.0.1:5433`.

## Operational note

Administrator sessions created before this change do not carry `admin_auth_generation`. The next request ends that session. Sign in again once. Cached login pages also need a refresh so the new CSRF field is present.

The MySQL container was recreated with the volume kept. The web container was not recreated, so the new PHP error-display setting is not active until that container starts again.

## Follow-up from the specialist reviews

| ID | Severity | Issue | Status |
| --- | --- | --- | --- |
| AUTH-07 | High | Anyone who knew a CIN could open that student’s portal before the student did | Fixed: signup also requires the birth date stored on the dossier, and failures use one message |
| AUTH-08 | High | `inscription_old.php` let a teacher or finance session rewrite any student, with no CSRF check | Fixed: the page now requires `students.edit` and redirects to `inscription.php` before any write |
| LOGIC-05 | Medium | The current inscription form did not check CSRF | Fixed |
| INJ-03 | High | Stage placement filters concatenated `PDO::quote()` output, and the PostgreSQL rewriter could pull double quotes out of that literal | Fixed: filters are bound parameters, and the rewriter no longer edits text inside single quotes |
| LOGIC-03 | High | Manual grade entry stored the semester from the form, so one subject could get a second row | Fixed: the stored semester is the subject’s semester, and it must match the group |
| LOGIC-06 | Medium | An administrator update could move an existing grade onto another student | Fixed: the update changes marks only, and rejects a different student, subject, or semester |
| LOGIC-07 | Medium | A missing attendance value was stored as present | Fixed: every student in the group must be sent as present or absent |
| LOGIC-08 | Medium | Two attendance-certificate requests could both be inserted | Fixed in schema: one pending request per student |
| FILE-05 | Medium | The default stage-document folder sat inside the project tree, and `public/exports` served a timetable with no login | Fixed: stage files default to `storage/stage-documents`, and `public/exports` is denied |

Promotion still averages every stored grade for a student and does not detach the old rows. That calculation was not changed, because doing so would alter academic results. It is listed in `REMAINING_RISKS.md`.
