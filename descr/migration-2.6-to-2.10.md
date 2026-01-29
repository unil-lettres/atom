# Migration 2.6 -> 2.10 (Export/Import Playbook)

This document captures the repeatable export/import process we used to bring a
2.6 database into the 2.10 Docker stack in this repo.

## Scope
- Source: legacy AtoM 2.6 (non-Docker or older Docker).
- Target: this repo's Docker stack (2.10 image).

## Prereqs
- Access to the 2.6 database and filesystem.
- Access to the 2.10 Docker host.
- Paths and credentials from `docker/etc/environment`.
- Enough disk space for a full DB dump + `uploads/` archive.

## 1) Export from 2.6
1) Put the 2.6 site in maintenance (or stop web/worker services).
2) Export the database:
   ```sh
   # Replace with your real DB host/user/name
   mysqldump --single-transaction --routines --triggers \
     -u <OLD_DB_USER> -p <OLD_DB_NAME> > atom-2.6.sql
   ```
3) Archive file assets (minimum: `uploads/`):
   ```sh
   tar -czf atom-2.6-uploads.tar.gz /path/to/atom/uploads
   ```
   Optional but recommended:
   ```sh
   tar -czf atom-2.6-downloads.tar.gz /path/to/atom/downloads
   tar -czf atom-2.6-data.tar.gz /path/to/atom/data

   # Example archives created from prod (2026-01-22):
   # - descr/atom-archives-uploads-20260122.tar.gz
   # - descr/atom-archives-downloads-20260122.tar.gz
   # - descr/atom-archives-data-20260122.tar.gz
   ```
4) Copy the SQL + archives to the 2.10 host.

## 2) Import into 2.10 Docker stack
1) Set the compose file (stage or local):
   ```sh
   export COMPOSE_FILE="$PWD/docker/docker-compose.stage.yml"
   # or for local: docker-compose.dev.yml (+ override if needed)
   ```
2) Start the stack (at least `percona` must be up):
   ```sh
   docker compose up -d percona
   ```
3) Restore the database into the Percona container:
   ```sh
   docker compose exec -T percona \
     mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" < /path/to/atom-2.6.sql
   ```
4) Restore file assets on the host (paths are bind-mounted in stage compose):
   ```sh
   tar -xzf /path/to/atom-2.6-uploads.tar.gz -C /path/to/atom
   # Optional:
   tar -xzf /path/to/atom-2.6-downloads.tar.gz -C /path/to/atom
   tar -xzf /path/to/atom-2.6-data.tar.gz -C /path/to/atom
   ```
5) Start the full stack:
   ```sh
   docker compose up -d
   ```

## 3) Run database migrations (2.6 -> 2.10)
Run the AtoM SQL upgrade task inside the `atom` container:
```sh
docker compose exec -T -u www-data atom \
  php /atom/src/symfony tools:upgrade-sql --no-confirmation
```
If it prompts for a theme, choose the correct one (ex: `arDominionB5Plugin`).

## 4) Rebuild caches and search index
```sh
docker compose exec -T -u www-data atom php /atom/src/symfony cc
docker compose exec -T -u www-data atom php /atom/src/symfony search:populate
```

## 5) Post-migration checks (minimum)
- Login as admin, load a fonds, ISAAR, and ISDIAH notice.
- Save an old ISAD/ISAAR/ISDIAH record (confirms no 500 on save).
- Run a special-character search.
- Generate and upload a finding aid.
- Verify imports/exports (CSV/XML) if required.

## 6) Common pitfalls / notes
- If uploads look missing, verify the `uploads/` bind mount.
- If search is empty, the ES index likely needs `search:populate`.
- If worker jobs fail, confirm `gearmand` + `atom_worker` are running.
- `downloads/` is optional; missing it only removes prior exports.

## 7) Rollback plan
- Keep the original 2.6 DB dump + uploads archive.
- If needed, stop the 2.10 stack, drop/replace the DB, and restore again.

## Reference database
- The staging DB (atom-archives-stage) is currently validated and can be used
  as a reference if issues appear during re-import.
