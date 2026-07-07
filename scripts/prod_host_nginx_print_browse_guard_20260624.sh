#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" != "0" ]]; then
  echo "Run as root, e.g. sudo bash $0" >&2
  exit 1
fi

ts="$(date -u +%Y%m%dT%H%M%SZ)"
nginx_conf="/etc/nginx/nginx.conf"
atom_vhost="/etc/nginx/sites-available/atom"
nginx_backup="$nginx_conf.bak-print-browse-guard-$ts"
vhost_backup="$atom_vhost.bak-print-browse-guard-$ts"

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

if "$atom_public_print_browse" not in text:
    marker = """\tlimit_req_zone $atom_public_client_key zone=atom_browse_rl:10m rate=5r/s;\n"""
    guard = """\t# Drop public print-browse scraper bursts before they reach Docker/PHP.\n\tmap "$atom_unil_client:$arg_media:$uri" $atom_public_print_browse {\n\t\tdefault 0;\n\t\t~^0:print:/index\\.php/informationobject/browse 1;\n\t\t~^0:print:/informationobject/browse 1;\n\t}\n\n"""
    if marker not in text:
        raise SystemExit("Expected host nginx browse zone marker not found")
    text = text.replace(marker, guard + marker, 1)
    nginx_conf.write_text(text)

text = atom_vhost.read_text()

if "atom_public_print_browse" not in text:
    marker = "  client_max_body_size 72M;\n\n"
    guard = """  if ($atom_public_print_browse) {\n    return 444;\n  }\n\n"""
    if marker not in text:
        raise SystemExit("Expected host nginx vhost insertion marker not found")
    text = text.replace(marker, marker + guard, 1)
    atom_vhost.write_text(text)
PY

if ! nginx -t; then
  echo "nginx -t failed; restoring backups" >&2
  restore
  nginx -t || true
  exit 1
fi

systemctl reload nginx

echo "Host nginx print-browse guard applied."
echo "Backups:"
echo "  $nginx_backup"
echo "  $vhost_backup"
