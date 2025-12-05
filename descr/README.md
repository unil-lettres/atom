# Stack start/stop (local laptop)

From the repo root (`/Users/jganivet/Développement/atom_docker/atom`):

```bash
# start
export COMPOSE_FILE="$PWD/docker/docker-compose.dev.yml:$PWD/docker/docker-compose.override.arm.yml"
docker compose up -d

# stop
export COMPOSE_FILE="$PWD/docker/docker-compose.dev.yml:$PWD/docker/docker-compose.override.arm.yml"
docker compose down
```

Services expose:
- nginx on 63001 (http://localhost:63001)
- MySQL (Percona) on 63003 (localhost-only)
- Elasticsearch on 63002 (localhost-only)
- Gearman on 63005, Memcached on 63004 (both localhost-only)

If you change compose files, set `COMPOSE_FILE` accordingly. In doubt, always export it before running `docker compose` commands.***
