# Remaining risks

These were confirmed as weaknesses and left in place because removing them would break a current workflow, or because a safe live retest was not possible.

## INFRA-04 — Credentials in the repository

`application/config.php` falls back to a database user and password when `DB_USER` / `DB_PASS` are unset. `docker-compose.yml` sets the same database password and a MySQL root password in environment values. `database/migrations/admin_migration.sql` inserts a fixed administrator password hash on first install. The duplicate-key clause does not reset that hash later.

This was not removed. Docker already injects environment variables, and the XAMPP fallback is what this machine uses to reach Postgres. Rotating the administrator password from **Changer le mot de passe** invalidates other administrator sessions after AUTH-02.

Do not commit a stronger secret into the same files. Move local overrides to an untracked env file when you next change the database password.

## AUTH-05 — Legacy teacher MD5 passwords

`application/Auth/login.php` still accepts a 32-character hex teacher password as MD5 and then rewrites it with `password_hash`. That keeps old accounts able to sign in. It is a weak verifier until each of those rows is upgraded. No new account is stored as MD5.

## AUTH-06 — Student registration still depends on data the student knows

Signup no longer opens an account from the CIN alone, and it no longer says whether the CIN is unknown or already registered. It still accepts anyone who knows the CIN and the birth date stored on the dossier. That is weaker than a code sent to the student’s email. There is no mail delivery in this application, so that check was not added.

## LOGIC-04 — Promotion averages every stored grade

`application/Pedagogie/promotion.php` averages every `note` row for the student and leaves those rows and group memberships in place after the level changes. CSRF, the administrator password, and the confirmation word are required, and the decision is recomputed on the server. The average was not changed in this audit: limiting it to the current level would change published results and needs an academic decision.

## LOGIC-09 — Stage approval capacity is not locked

`application/Stage/stage_suivi.php` checks capacity and then updates the placement status in separate statements. Two approvals at the same moment can both pass the count. New placements are inserted as drafts, so the window is the approval action. The publish path already locks rows. This approval path was not wrapped in `SELECT … FOR UPDATE` in this pass.

## INJ-02 — Some staff pages still show exception text

Many administrative catch blocks assign `$exception->getMessage()` to the on-page error. Several of those exceptions are intentional validation messages (`InvalidArgumentException`, `RuntimeException`). A `PDOException` on the same path would show SQL text to a signed-in staff user. Certificate download endpoints were changed. The remaining staff forms were not blanket-replaced, because that would hide the validation sentences the screens use today.

## INFRA-05 — No Content-Security-Policy

Responses now send `nosniff`, `SAMEORIGIN`, a referrer policy, a permissions policy, and HSTS on HTTPS. A CSP that blocks inline scripts would stop the staff and student pages, which use inline scripts for themes and panels. CSP was not added.

## INFRA-06 — Startup hardening is not in the running web container

`docker/start-web.sh` turns off `display_errors` and `expose_php` the next time the web container starts. The container that is running now was not recreated.

## INFRA-01 follow-up — XAMPP deny rules were not fetched

The `.htaccess` rules are in the tree and covered by the regression test as text. Apache on port 80 was down, so a browser request was not sent.

## Rate-limit tradeoff

Sixty failures from one IP in fifteen minutes locks every login from that address. A laboratory behind one NAT can hit that if many people mistype passwords together. The per-identity lock is eight failures. Raise the IP ceiling in `auth_rate_limited()` if a shared lab is locked out in practice.

## Dependency and scanner gap

No dependency audit tool was run. Outdated Bootstrap is loaded from a CDN. That is a supply-chain exposure if the CDN is compromised, and it is also why a strict CSP was not applied.
