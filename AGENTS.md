# AtoM Docker Project — Agent Notes

<!-- BEGIN SHARED AGENT CONTEXT -->
## Shared agent context entry point

When a conversation starts with `context atom`, `contexte atom`, or
`../../agents/kickstart.md atom`, treat it as a context-load command before answering
project-specific questions. Do not infer project context from active IDE tabs,
shell history, approved command prefixes, or recent conversation drift.

Load the shared and project-specific rules before acting:

- `../../agents/kickstart.md`
- `../../agents/agents.yaml`
- `../../agents/phases.yaml`
- stack files referenced by `../../agents/CONTEXT.atom.md`
- `../../agents/CONTEXT.atom.md`
- `../../agents/projects/atom/project-policy.yaml`
- `../../unil-ops/data/inventory/projects.json` entry for `atom`
- relevant `../../unil-ops` inventory, runbooks, health cache, and backup metadata through
  the `unil_ops` MCP server when available

The context-load confirmation should be short and include:

```text
context atom loaded.
MCP: unil_ops available
Agent context loaded: ../../agents/CONTEXT.atom.md.
Scope: AtoM Archives project; app worktree atom_docker/atom and stack wrapper atom_docker; docs in descr/; services atom-archives and atom-archives-stage.
Before acting, I will state the concrete target environment, host/service,
worktree/docs root, runtime, and rollback boundary when relevant.
```

If the registered MCP tools are not visible in the current Codex session, use:

```text
MCP: unil_ops registered but not available in this session
```

Context loading is preparation, not authorization. Production writes,
deployments, VM maintenance, restores, and rollback steps still require the
explicit project boundary preflight and the relevant runbook or request file.
<!-- END SHARED AGENT CONTEXT -->

## Quick start/stop (local)
From repo root:
```bash
export COMPOSE_FILE="$PWD/docker/docker-compose.dev.yml:$PWD/docker/docker-compose.override.arm.yml"
docker compose build atom atom_worker   # one-time after Dockerfile changes
docker compose up -d

# stop
docker compose down
```

## Staging deploy (DockerHub image)
On VM:
```bash
export COMPOSE_FILE="/home/deployer/atom/docker/docker-compose.stage.yml"
docker compose pull atom atom_worker
docker compose up -d
docker compose exec -T -u www-data atom php /atom/src/symfony cc
```
If image changed, refresh shared code volume:
```bash
docker volume rm docker_atom_src
docker compose up -d
```
If changes still don't show, drop/recreate the code volume:
```bash
docker compose down
docker volume rm docker_atom_src
docker compose up -d
```

Default language (French) and cache clear:
```bash
docker compose exec -T atom sh -lc "sed -i 's/^    default_culture:.*/    default_culture:        fr/' /atom/src/apps/qubit/config/settings.yml"
docker compose exec -T atom php /atom/src/symfony cc --env=prod
```

Force HTTPS base URL in DB settings (prevents mixed-content download links):
```bash
docker compose exec -T percona sh -lc 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" "$MYSQL_DATABASE" -e "update setting_i18n si join setting s on s.id=si.id set si.value=\"https://atom-archives-stage.unil.ch\" where s.name=\"siteBaseUrl\" and si.culture in (\"en\",\"fr\");"'
docker compose exec -T atom php /atom/src/symfony cc --env=prod
```

Logo update (theme):
```bash
cp /path/to/logo.png /home/deployer/atom/plugins/arLettresB5Plugin/images/logo.png
docker compose exec -T atom php /atom/src/symfony cc --env=prod
```

Quick verify:
```bash
curl -s https://atom-archives-stage.unil.ch/ | head -n 5    # <html lang="fr">
curl -I https://atom-archives-stage.unil.ch/plugins/arLettresB5Plugin/images/logo.png
```

## Production deploy (DockerHub image)
Prod runs the full AtoM stack with Docker Compose under `/home/deployer/atom`.
Host nginx remains the public TLS frontend and proxies to Docker nginx on
`127.0.0.1:8081`. Legacy host services are kept stopped/disabled for rollback
only.

On VM:
```bash
cd /home/deployer/atom
export COMPOSE_FILE="/home/deployer/atom/docker/docker-compose.prod.yml"

# WARNING: docker/.env has priority over docker/etc/environment for ATOM_IMAGE.
# Ensure both files are aligned before pull/redeploy.
grep -n '^ATOM_IMAGE=' /home/deployer/atom/docker/.env /home/deployer/atom/docker/etc/environment
flock -n /tmp/atom-percona-backup.lock /home/deployer/atom/docker/scripts/backup_percona.sh

docker compose pull atom atom_worker
docker compose down
docker volume rm docker_atom_src
docker compose up -d
docker compose exec -T -u www-data atom php /atom/src/symfony cc --env=prod
```

Post-deploy runtime safeguards:
```bash
docker compose exec -T atom sh -lc "sed -i 's/^    default_culture:.*/    default_culture:        fr/' /atom/src/apps/qubit/config/settings.yml"
docker compose exec -T percona sh -lc 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" "$MYSQL_DATABASE" -e "update setting_i18n si join setting s on s.id=si.id set si.value=\"https://atom-archives.unil.ch\" where s.name=\"siteBaseUrl\" and si.culture in (\"en\",\"fr\");"'
docker compose exec -T -u www-data atom php /atom/src/symfony cc --env=prod
```

Quick verify:
```bash
curl -sL -o /tmp/prod-home.html -w "home_status=%{http_code}\n" https://atom-archives.unil.ch/
grep -n '<html lang=' /tmp/prod-home.html | head -n 1
curl -sI https://atom-archives.unil.ch/plugins/arLettresB5Plugin/images/logo.png | head -n 12
```

## Test Evidence / QA Log
- Manual QA sheet (Nam bug sheet / test plan): `descr/Plan_tests_AtoM_v2-10.xlsx` (see also CSV exports in `descr/`).
- Policy: when tests are run during an agent-assisted session, record what was executed + results in `../../agents/projects/atom/requests/*.yaml` under `testing.*` and `tracking.test_runs`.
- Existing record pointer: `../../agents/projects/atom/requests/2026-02-07T0000Z.reporting.atom.plan-tests-v2-10-record.yaml`.

## Local backup refresh policy
- Local backup root: `/Users/jganivet/Développement/Backups/atom-archives.unil.ch`.
- Local backup entry point: `/Users/jganivet/Développement/Backups/atom-archives.unil.ch/README.md`.
- Cross-application backup model: `/Users/jganivet/Développement/Backups/BACKUP-CANONICAL-MODEL.md`.
- Ops runbook: `/Users/jganivet/Développement/unil-ops/docs/runbooks/vms/atom-archives.md`.
- When refreshing the local prod backup, include the latest existing DB dump, VM recovery/config files, and refreshed media archives (`uploads`, `downloads`, `data`) unless explicitly told otherwise.
- Keep prod read-only for ad hoc local backup refreshes: copy existing dumps and stream tar output over SSH; do not generate new dumps or create archives on the VM.

## Ports (local + VM)
- nginx: 63001
- MySQL: 63003 (localhost-only)
- Elasticsearch: 63002 (localhost-only)
- Gearman: 63005 (localhost-only)
- Memcached: 63004 (localhost-only)

## Known fixes (Dec 2025)
- OIDC: disable plugin if not configured (`plugins/arOidcPlugin` -> `plugins/arOidcPlugin.disabled`), clear cache.
- Menu list 500: patch `lib/model/QubitMenu.php` (array unserialize), fix memcached host, clear cache.
- PHP-FPM IPv4 listen: set to `0.0.0.0:9000` in `docker/bootstrap.php`, restart container.

## VM layout (prod reference)
See `descr/prod-vm-notes.md` for prod paths and stack details.

## Migration notes
See `descr/migration-2.6-to-2.10.md` for export/import playbook.

## Language defaults
- Default culture set to French (`fr`) in `apps/qubit/config/settings.yml`.
- English remains enabled; FR is the default for public users.

## Prod health baseline (2026-03-09)
- Health checks passed on `atom-archives.unil.ch`: home `200`, `<html lang="fr">`, logo endpoint `200`, `siteBaseUrl` en/fr forced to HTTPS.
- Stack status healthy (`atom`, `atom_worker`, `nginx`, `percona`, `elasticsearch`, `memcached`, `gearmand` all up).
- PHP-FPM capacity tuned in generator (`/atom/src/docker/bootstrap.php`) and applied via bootstrap + restart:
  - `pm.max_children` increased from `5` to `14` to avoid recurrent saturation warnings.
  - Active runtime config confirmed at `/usr/local/etc/php-fpm.d/atom.conf`.
- Operational follow-up required: backup share `/home/deployer/dumps` reached ~95% usage; run retention cleanup before next backup cycle.

## Quick prod health check command
On prod VM (`/home/deployer/atom`):
```bash
cd /home/deployer/atom
./scripts/prod_health_check.sh
```
Notes:
- Exit code `0`: no blocking failure detected (`warnings` may still be present).
- Exit code `1`: at least one blocking failure (service down, stale/missing backup, or critical disk usage).

## Backlog pointer (hardening/perf)
- Follow-up planning backlog (2026-03-11): `../../agents/projects/atom/requests/2026-03-11T0914Z.planning.atom.prod-hardening-and-performance-followups.yaml`
- Scope: VM edge security headers, server banner hardening, crawler control refinement, and nginx buffering/performance tuning.
