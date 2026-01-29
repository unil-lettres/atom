# Prod VM notes (atom-archives.unil.ch)

## Paths
- AtoM root: `/usr/share/nginx/atom`
- Media folders:
  - `/usr/share/nginx/atom/uploads` (primary digital objects)
  - `/usr/share/nginx/atom/downloads`
  - `/usr/share/nginx/atom/data`

## Web stack
- Nginx site root: `/usr/share/nginx/atom` (from `/etc/nginx/sites-enabled/atom`)
- PHP-FPM pool: `/etc/php/7.4/fpm/pool.d/atom.conf` (user `www-data`)

## Processes observed
- `nginx` master + workers
- `php-fpm` pool `atom`
- `php7.4 symfony jobs:worker` running as `www-data`

## Other artifacts in /home/deployer
- `atom_dump.sql` and `atom_uploads.tar.gz`
- `~/dumps` is a NAS mount for scheduled DB dumps
