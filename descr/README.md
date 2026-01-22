# Stack start/stop (local laptop)

From the repo root (`/Users/jganivet/Développement/atom_docker/atom`):

```bash
# one-time rebuild after Dockerfile changes (php extensions, etc.)
export COMPOSE_FILE="$PWD/docker/docker-compose.dev.yml:$PWD/docker/docker-compose.override.arm.yml"
docker compose build atom atom_worker

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

## Staging stack (DockerHub image)

On the VM, use the image-based compose file and pull on each update:

```bash
export COMPOSE_FILE="/home/deployer/atom/docker/docker-compose.stage.yml"
docker compose pull atom atom_worker
docker compose up -d
docker compose exec -T -u www-data atom php /atom/src/symfony cc
```

When a new image is pushed, refresh the shared code volume before restart:

```bash
docker volume rm docker_atom_src
docker compose up -d
```

Services expose:
- nginx on 63001 (http://localhost:63001)
- MySQL (Percona) on 63003 (localhost-only)
- Elasticsearch on 63002 (localhost-only)
- Gearman on 63005, Memcached on 63004 (both localhost-only)

If you change compose files, set `COMPOSE_FILE` accordingly. In doubt, always export it before running `docker compose` commands.***

## Menu list 500 on VMs (Dec 2025)

Symptom: `/index.php/menu/list` 500s right after login on a VM. Root causes we hit:
- Code: `QubitMenu::isProtected()` tried `unserialize()` on an array loaded from YAML; fixed by tolerating arrays.
- Sessions: the VM had no memcached host called `memcached`, so CSRF/login failed (expired token). Either ensure memcached is reachable at `memcached`, or point sessions to `127.0.0.1`/filesystem.
- VM code path: the app runs in Docker with `/home/deployer/atom` bind-mounted into the container (`/atom/src`). Patching `/usr/share/nginx/atom` does nothing.

Fixes we applied:
- Code: patched `lib/model/QubitMenu.php` to accept array `app_menu_locking_info` (backported locally).
- VM session backend: install/start memcached and add `memcached` -> `127.0.0.1` to `/etc/hosts` (or switch `apps/qubit/config/factories.yml` to file sessions or `host: 127.0.0.1`), then `php symfony cc` and restart PHP-FPM.
- VM Docker bind mount: apply code patches under `/home/deployer/atom`, then clear cache inside the container and restart it.
- VM Docker FPM: php-fpm was listening on IPv6 only (`[::]:9000`), while nginx connects over IPv4. Changed `docker/bootstrap.php` to `listen = 0.0.0.0:9000` and restarted `docker-atom-1`.
- Locales: ensure PHP intl is available in the container (Dockerfile now installs it; rebuild images when it changes).

Checklist for future VM installs:
1) Ensure memcached is running and resolvable (`memcache_connect('memcached',11211)` or change host to `127.0.0.1` / file sessions).
2) Clear caches and restart PHP-FPM after changing session backend.
3) With the patched code, `/index.php/menu/list` should load once logged in.

## VM deployment workflow (DockerHub image)

Staging uses `unillett/atom:development` via `docker/docker-compose.stage.yml`.
The app code lives in a named volume (`docker_atom_src`) so nginx and php-fpm share the same tree.
Uploads, downloads, data, and cache are bind-mounted from `/home/deployer/atom` so they survive image updates.

Deploy updates:
1) `docker compose pull atom atom_worker`
2) If the image changed, drop the shared code volume:
   - `docker volume rm docker_atom_src`
3) `docker compose up -d`
4) `docker compose exec -T -u www-data atom php /atom/src/symfony cc`

Worker note:
- Keep `docker-atom_worker-1` running; restart with `docker compose restart atom_worker` if needed.
