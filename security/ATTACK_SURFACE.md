# Attack surface

Front controller: `public/index.php` loads `application/routes.php` and includes the matching file under `application/`. Browser paths stay extensionless (`/login`, `/dashboard`). `public/.htaccess` sends those paths to the front controller and leaves real files in `public/` alone.

## Trust boundaries

| Boundary | Where it is enforced |
| --- | --- |
| Anonymous vs signed-in | `require_login()`, `require_admin()`, `require_finance()`, `require_stage()`, `require_permission()` |
| Student vs staff | Separate session cookie name `ESG_STUDENT` for student scripts |
| Teacher vs another teacher | `current_teacher_id()` checked against `enseignant_affectation` and course ownership |
| Student vs another student | `$_SESSION['id_etudiant']` compared with the row owner on dashboard, course files, stage documents, and timetable PDFs |
| Browser vs state change | `verify_csrf()` on state-changing staff forms and student account creation |
| Upload vs disk | `is_uploaded_file`, MIME and signature checks, storage under `storage/` or `/var/lib/upsis`, not under `public/` |

`application/config.php` calls `validate_current_account_status()` on each request except `account_status.php` and `etudiant_inbox.php`. Suspended accounts and bumped `auth_generation` values end the session.

## Entry points

Authentication: `login.php`, `connexion_etudiant.php`, `creer_espace_etudiant.php`, `logout.php`, `changer_mot_de_passe.php`, `account_status.php`.

Student: `dashboard.php` (profile, timetable, courses, grades, attendance request), `etudiant_inbox.php`, `cours_fichier.php`, `emploi_pdf.php`, `stage_dossier.php`, `stage_document.php`.

Staff modules, all routed from `application/routes.php`: accounts, enrollment (`inscription.php`, `identite.php`), teachers, academic structure, groups, timetable, grades, attendance, stage, finance, certificates, audit journal.

Maintenance script that is also routed: `fix_utf8.php`.

Uploads: course PDFs (`mes_cours.php` → `course_library.php`), timetable PDFs (`emploi_pdf.php`), stage documents (`stage_docs.php`), institution logo (`institution.php`).

Downloads: `cours_fichier.php`, `emploi_pdf.php`, `stage_document.php`, certificate generators, `download_all_inscriptions.php`, `archive_export.php`, `etudiants_export.php`, `recu_paiement.php`.

## Data flow for a course file

Teacher POST → CSRF and teacher session → `course_owned_folder()` → `course_check_pdf()` → random `stored_name` under `storage/uploads/cours` → row in `cours_fichier`.  
Student GET `cours_fichier.php` → student session → `course_student_may_access_file()` → `readfile` of the resolved path under the storage root.

## Data flow for a grade

Teacher or administrator POST `gestion_notes.php` → CSRF → role gate → for a teacher, `enseignant_affectation` and group membership → prepared `INSERT`/`UPDATE` on `note`.

## Infrastructure

- Docker web: document root `public/`, HTTP on port 80 redirects to `https://<LAN>:8443`, TLS terminates in Apache. HAProxy on 8443 forwards TCP to that Apache.
- Docker Postgres: `127.0.0.1:5433`.
- Docker MySQL: published for local tools. After this audit, `127.0.0.1:3307` only.
- XAMPP: `DocumentRoot "C:/xampp/htdocs"` with `AllowOverride All` and `Options Indexes`. The project directory is `C:/xampp/htdocs/webapp`. Apache on port 80 was not running during this audit, so the deny rules were not exercised over HTTP.

## Dangerous sinks checked

`eval`, `unserialize`, `extract($_POST)`, and dynamic includes of request paths were not found in application code. `exec` appears in certificate conversion and the bulk inscription PDF, with internal paths passed through `escapeshellarg`. `SHOW COLUMNS` and schema `ALTER` statements use fixed identifiers. Request values that reach SQL in the reviewed grade, login, course, and dashboard queries are bound parameters.
