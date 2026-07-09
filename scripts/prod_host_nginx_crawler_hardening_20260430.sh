#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" != "0" ]]; then
  echo "Run as root, e.g. sudo bash $0" >&2
  exit 1
fi

ts="$(date -u +%Y%m%dT%H%M%SZ)"
nginx_conf="/etc/nginx/nginx.conf"
atom_vhost="/etc/nginx/sites-available/atom"

cp -a "$nginx_conf" "$nginx_conf.bak-$ts"
cp -a "$atom_vhost" "$atom_vhost.bak-$ts"

python3 - <<'PY'
from pathlib import Path

nginx_conf = Path("/etc/nginx/nginx.conf")
atom_vhost = Path("/etc/nginx/sites-available/atom")

text = nginx_conf.read_text()
needle = "\tlimit_req_zone $binary_remote_addr zone=atom_browse_rl:10m rate=5r/s;\n"
insert = (
    "\tlimit_req_zone $binary_remote_addr zone=atom_browse_rl:10m rate=5r/s;\n"
    "\tlimit_req_zone $server_name zone=atom_browse_global_rl:1m rate=10r/s;\n"
    "\tlimit_req_zone $binary_remote_addr zone=atom_tree_rl:10m rate=12r/m;\n"
    "\tlimit_conn_zone $binary_remote_addr zone=atom_tree_conn:10m;\n"
)
if "zone=atom_browse_global_rl" not in text:
    if needle not in text:
        raise SystemExit("Cannot find atom_browse_rl zone in /etc/nginx/nginx.conf")
    text = text.replace(needle, insert, 1)
nginx_conf.write_text(text)

text = atom_vhost.read_text()

old_browse = """location ^~ /index.php/informationobject/browse {
  limit_req zone=atom_browse_rl burst=30 nodelay;
  limit_req_status 429;

  proxy_pass http://127.0.0.1:8081;
  proxy_set_header Host $host;
  proxy_set_header X-Real-IP $remote_addr;
  proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
  proxy_set_header X-Forwarded-Proto $scheme;
  proxy_set_header X-Forwarded-Host $host;
  proxy_set_header X-Forwarded-Port 443;
  proxy_read_timeout 300;
  proxy_send_timeout 300;
  proxy_redirect off;
}
"""

new_browse = """location ^~ /index.php/informationobject/browse {
  limit_req zone=atom_browse_rl burst=30 nodelay;
  limit_req zone=atom_browse_global_rl burst=20 nodelay;
  limit_req_status 429;

  proxy_pass http://127.0.0.1:8081;
  proxy_set_header Host $host;
  proxy_set_header X-Real-IP $remote_addr;
  proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
  proxy_set_header X-Forwarded-Proto $scheme;
  proxy_set_header X-Forwarded-Host $host;
  proxy_set_header X-Forwarded-Port 443;
  proxy_read_timeout 300;
  proxy_send_timeout 300;
  proxy_redirect off;
}

location ^~ /informationobject/browse {
  limit_req zone=atom_browse_rl burst=30 nodelay;
  limit_req zone=atom_browse_global_rl burst=20 nodelay;
  limit_req_status 429;

  proxy_pass http://127.0.0.1:8081;
  proxy_set_header Host $host;
  proxy_set_header X-Real-IP $remote_addr;
  proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
  proxy_set_header X-Forwarded-Proto $scheme;
  proxy_set_header X-Forwarded-Host $host;
  proxy_set_header X-Forwarded-Port 443;
  proxy_read_timeout 300;
  proxy_send_timeout 300;
  proxy_redirect off;
}
"""

if "zone=atom_browse_global_rl" not in text:
    if old_browse not in text:
        raise SystemExit("Cannot find expected browse location in /etc/nginx/sites-available/atom")
    text = text.replace(old_browse, new_browse, 1)

tree_location = """  location ~ /informationobject/fullWidthTreeView {
    limit_req zone=atom_tree_rl burst=4 nodelay;
    limit_req_status 429;
    limit_conn atom_tree_conn 1;

    proxy_pass http://127.0.0.1:8081;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-Port 443;
    proxy_read_timeout 300;
    proxy_send_timeout 300;
    proxy_redirect off;
  }

"""

if "zone=atom_tree_rl" not in text:
    marker = "  location / {\n"
    if marker not in text:
        raise SystemExit("Cannot find location / marker in /etc/nginx/sites-available/atom")
    text = text.replace(marker, tree_location + marker, 1)

atom_vhost.write_text(text)
PY

nginx -t
systemctl reload nginx

echo "Host nginx crawler hardening applied."
echo "Backups:"
echo "  $nginx_conf.bak-$ts"
echo "  $atom_vhost.bak-$ts"
