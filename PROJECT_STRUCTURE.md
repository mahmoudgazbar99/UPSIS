# Project structure

Procedural PHP ERP with a clean split between the public web root and application code.

## Layout

```text
public/                 HTTP document root (only public surface)
  accueil.php           Landing page (public)
  index.php             Front controller for ERP URLs
  assets/ images/ exports/
  .htaccess             Rewrites /finance.php etc. → index.php

application/            All functional ERP code
  Auth/                 Login, logout, student access
  Comptes/              Account admin (staff, finance users, stage accounts)
  Admin/                Audit / utilities
  Scolarite/            Registration, identity, establishment
  Pedagogie/            Structure, groups, promotion policies
  Presence/             Attendance
  Notes/                Grades
  Documents/            Certificates & exports
  Finance/              Payments & fees
  Stage/                Internships
  Enseignant/           Teachers
  Dashboard/            Staff & student homes
  support/              Shared libraries (roles, institution, certificates, …)
  bootstrap.php         Path helpers + loads config
  config.php            Sessions, db(), permissions
  layout.php            Staff UI chrome
  routes.php            Legacy URL → application file map

config/                 Declarative settings
database/               migrations/, seeds/, backups/
storage/                Writable runtime data
templates/              Private Word templates
scripts/ tests/ docs/ docker/
```

## URL compatibility

Browser URLs are unchanged (`/login.php`, `/finance.php`, `/stage_portal.php`, …).

`public/.htaccess` rewrites those requests to `public/index.php`, which:

1. Restores `SCRIPT_NAME` to the legacy filename (role guards keep working)
2. Loads `application/bootstrap.php`
3. Dispatches via `application/routes.php`

The landing page is served directly as `public/accueil.php`.

## Docker

Apache DocumentRoot is `/var/www/html/public` (`docker/start-web.sh`).
Private trees (`application/`, `database/`, `templates/`, `storage/`) are not web-accessible.

## Where to add code

| Work | Place |
|------|--------|
| New ERP screen | `application/<Module>/` + entry in `application/routes.php` |
| Shared helper | `application/support/` |
| Public CSS/JS/image | `public/assets` or `public/images` |
| SQL | `database/migrations|seeds|backups` |
