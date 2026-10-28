# PostgreSQL cutover and MySQL rollback

PostgreSQL 16.6 is the application database. The original MySQL 8.4 service, its `mysql_data` volume, and the backup below stay in place until an explicit cleanup is approved. Nothing in the migration deletes that volume.

## Backup

- Logical backup taken before the cutover: `C:\xampp\backups\ecole_sante_gabes_pre_postgres.sql`
- That file is outside the web root and is not part of the git repository.
- Uploaded Stage documents stay on the Docker volume `stage_documents` (`/var/lib/upsis/stage-documents`). They are not stored in either database volume.
- The legacy logo and old stage blobs that were stored in MySQL are copied into PostgreSQL `bytea` columns. The files on `stage_documents` are unchanged.

## Start the PostgreSQL application

From `C:\xampp\htdocs\webapp`:

```powershell
docker compose up -d
```

The site stays on http://127.0.0.1:8080 and http://192.168.100.95:8080. PostgreSQL listens only on 127.0.0.1:5433. MySQL remains available on port 3307 for rollback checks.

Repeat a data copy, without touching MySQL, with:

```powershell
docker compose run --rm --no-deps web php scripts/migrate_mysql_to_pgsql.php
```

A rerun rebuilds only the PostgreSQL `public` schema. It does not dump, drop, or update MySQL.

## Restore the MySQL application

1. Stop the web container so it cannot write to either database: `docker compose stop web`
2. In `docker-compose.yml`, point `web.environment` back to MySQL: `DB_HOST=mysql`, `DB_PORT=3306`.
3. In `config.php`, restore the previous MySQL PDO connection (`mysql:host=...;charset=utf8mb4`) before `db()` returns `AppPdo`.
4. Start again: `docker compose up -d`
5. If the MySQL volume itself was damaged, restore it from the backup into the existing `mysql` service. Do not remove `mysql_data` first. Load the backup with the MySQL client inside `myapp_db`.

Records created in PostgreSQL after the cutover are not present in MySQL. Take a fresh PostgreSQL dump before rolling back if those records must be kept.

## Do not remove yet

Do not run `docker compose down -v`. Do not delete `mysql_data`, `myapp_db`, or `C:\xampp\backups\ecole_sante_gabes_pre_postgres.sql` until the PostgreSQL application has been accepted in production.
