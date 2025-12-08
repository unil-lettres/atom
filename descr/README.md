# Stack start/stop (local laptop)

From the repo root (`/Users/jganivet/Développement/atom_docker/atom`):

```bash
# start
export COMPOSE_FILE="$PWD/docker/docker-compose.dev.yml:$PWD/docker/docker-compose.override.arm.yml"
docker compose up -d

## Staging login/SSO incident note (Dec 2025)

Symptom: on staging the login UI was unstyled, “Ouverture de session” dead, SSO button returned 500. Root cause: OIDC was enabled on the VM while the code path expected helpers and a different authenticate signature.

Final fix that worked:
- Disabled OIDC entirely: remove/ignore `activate-oidc-plugin`, comment out the enable block in `config/ProjectConfiguration.class.php`, and rename `plugins/arOidcPlugin` to `plugins/arOidcPlugin.disabled`.
- Clear cache and restart (`docker compose exec atom php symfony cc`, then `docker compose restart atom`), then hard-refresh the browser.

Optional hardening we kept: added `parseProviderIdFromUrl()` and `validateProviderId()` to `lib/myUser.class.php` so future OIDC toggles don’t hit missing methods.

Result: 500s gone; styled login works with local credentials. For future moves: either fully configure OIDC, or disable the plugin and clear cache before first start on the VM.

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
