#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" != "0" ]]; then
  echo "Run as root, e.g. sudo bash $0" >&2
  exit 1
fi

ts="$(date -u +%Y%m%dT%H%M%SZ)"
nginx_conf="/etc/nginx/nginx.conf"
atom_vhost="/etc/nginx/sites-available/atom"
nginx_backup="$nginx_conf.bak-unil-rate-limits-$ts"
vhost_backup="$atom_vhost.bak-unil-rate-limits-$ts"

cp -a "$nginx_conf" "$nginx_backup"
cp -a "$atom_vhost" "$vhost_backup"

restore() {
  cp -a "$nginx_backup" "$nginx_conf"
  cp -a "$vhost_backup" "$atom_vhost"
}

python3 - <<'PY'
from pathlib import Path

nginx_conf = Path("/etc/nginx/nginx.conf")
atom_vhost = Path("/etc/nginx/sites-available/atom")

text = nginx_conf.read_text()

old_zones = """\tlimit_req_zone $binary_remote_addr zone=atom_browse_rl:10m rate=5r/s;
\tlimit_req_zone $server_name zone=atom_browse_global_rl:1m rate=10r/s;
\tlimit_req_zone $binary_remote_addr zone=atom_tree_rl:10m rate=12r/m;
\tlimit_conn_zone $binary_remote_addr zone=atom_tree_conn:10m;
"""

new_zones = """\t# Split public and UNIL traffic so public crawler bursts do not consume
\t# the same host-edge browse/tree budgets as local UNIL users.
\tgeo $atom_unil_client {
\t\tdefault 0;
\t\t130.223.0.0/16 1;
\t\t2001:620:610::/48 1;
\t}

\tmap $atom_unil_client $atom_public_client_key {
\t\t0 $binary_remote_addr;
\t\t1 "";
\t}

\tmap $atom_unil_client $atom_unil_client_key {
\t\t0 "";
\t\t1 $binary_remote_addr;
\t}

\tmap $atom_unil_client $atom_public_global_key {
\t\t0 $server_name;
\t\t1 "";
\t}

\tmap $atom_unil_client $atom_unil_global_key {
\t\t0 "";
\t\t1 $server_name;
\t}

\tlimit_req_zone $atom_public_client_key zone=atom_browse_rl:10m rate=5r/s;
\tlimit_req_zone $atom_unil_client_key zone=atom_browse_unil_rl:10m rate=15r/s;
\tlimit_req_zone $atom_public_global_key zone=atom_browse_global_rl:1m rate=10r/s;
\tlimit_req_zone $atom_unil_global_key zone=atom_browse_unil_global_rl:1m rate=30r/s;
\tlimit_req_zone $atom_public_client_key zone=atom_tree_rl:10m rate=12r/m;
\tlimit_req_zone $atom_unil_client_key zone=atom_tree_unil_rl:10m rate=60r/m;
\tlimit_conn_zone $atom_public_client_key zone=atom_tree_conn:10m;
\tlimit_conn_zone $atom_unil_client_key zone=atom_tree_unil_conn:10m;
"""

if "zone=atom_browse_unil_rl" not in text:
    if old_zones not in text:
        raise SystemExit("Expected host nginx rate-limit zone block not found")
    text = text.replace(old_zones, new_zones, 1)

nginx_conf.write_text(text)

text = atom_vhost.read_text()

text = text.replace(
    "  limit_req zone=atom_browse_rl burst=30 nodelay;\n"
    "  limit_req zone=atom_browse_global_rl burst=20 nodelay;\n"
    "  limit_req_status 429;\n",
    "  limit_req zone=atom_browse_rl burst=30 nodelay;\n"
    "  limit_req zone=atom_browse_unil_rl burst=60 nodelay;\n"
    "  limit_req zone=atom_browse_global_rl burst=20 nodelay;\n"
    "  limit_req zone=atom_browse_unil_global_rl burst=80 nodelay;\n"
    "  limit_req_status 429;\n",
)

text = text.replace(
    "    limit_req zone=atom_tree_rl burst=4 nodelay;\n"
    "    limit_req_status 429;\n"
    "    limit_conn atom_tree_conn 1;\n",
    "    limit_req zone=atom_tree_rl burst=4 nodelay;\n"
    "    limit_req zone=atom_tree_unil_rl burst=20 nodelay;\n"
    "    limit_req_status 429;\n"
    "    limit_conn atom_tree_conn 1;\n"
    "    limit_conn atom_tree_unil_conn 2;\n",
)

atom_vhost.write_text(text)
PY

if ! nginx -t; then
  echo "nginx -t failed; restoring backups" >&2
  restore
  nginx -t || true
  exit 1
fi

systemctl reload nginx

echo "Host nginx UNIL-aware rate-limit cleanup applied."
echo "Backups:"
echo "  $nginx_backup"
echo "  $vhost_backup"
