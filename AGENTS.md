# AtoM Docker Project — Agent Notes

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
