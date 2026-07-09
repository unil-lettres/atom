#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" != "0" ]]; then
  echo "Run as root, e.g. sudo bash $0" >&2
  exit 1
fi

ts="$(date -u +%Y%m%dT%H%M%SZ)"
atom_vhost="/etc/nginx/sites-available/atom"

cp -a "$atom_vhost" "$atom_vhost.bak-$ts"

python3 - <<'PY'
from pathlib import Path

atom_vhost = Path("/etc/nginx/sites-available/atom")
text = atom_vhost.read_text()

headers = """  add_header X-Content-Type-Options "nosniff" always;
  add_header Referrer-Policy "strict-origin-when-cross-origin" always;
  add_header Permissions-Policy "accelerometer=(), autoplay=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()" always;
  add_header Strict-Transport-Security "max-age=3600" always;
"""

if "add_header X-Content-Type-Options" not in text:
    marker = "  fastcgi_hide_header X-Powered-By;\n"
    if marker not in text:
        raise SystemExit("Cannot find header insertion marker in /etc/nginx/sites-available/atom")
    text = text.replace(marker, marker + headers, 1)

atom_vhost.write_text(text)
PY

nginx -t
systemctl reload nginx

echo "Host nginx security headers applied."
echo "Backup:"
echo "  $atom_vhost.bak-$ts"
