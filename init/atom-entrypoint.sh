#!/usr/bin/env bash
set -e

mkdir -p /run/php
chown -R www-data:www-data /run/php

# ----------------------
# ENVIRONMENT VARIABLES
# ----------------------
DB_HOST="${DB_HOST:-mysql}"
DB_NAME="${DB_NAME:-atom}"
DB_USER="${DB_USER:-atom}"
DB_PASS="${DB_PASS:-atompass}"
ES_HOST="${ES_HOST:-elasticsearch}"
ES_PORT="${ES_PORT:-9200}"
GEARMAND_HOST="${GEARMAND_HOST:-gearman}"
MEMCACHED_HOST="${MEMCACHED_HOST:-memcached}"
MEMCACHED_PORT="${MEMCACHED_PORT:-11211}"
ATOM_VERSION="${ATOM_VERSION:-2.9.0}"
ATOM_DIR="/usr/share/nginx/atom"
TARBALL="/init/atom-${ATOM_VERSION}.tar.gz"
SITE_URL="${SITE_URL:-http://localhost:8080}"
MAX_RETRIES=60

# ----------------------
# LOGGING SETUP
# ----------------------
LOG_FILE="/var/log/atom-init.log"
mkdir -p "$(dirname "$LOG_FILE")"
exec > >(tee -a "${LOG_FILE}") 2>&1

# ----------------------
# HELPER FUNCTION
# ----------------------
retry_until_success() {
  local name="$1" cmd="$2" i=0
  echo ">>> Waiting for $name to be reachable..."
  until eval "$cmd" >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge "$MAX_RETRIES" ]; then
      echo "ERROR: Timeout while waiting for $name."
      exit 1
    fi
    echo "$name is not online yet; waiting 2 seconds..."
    sleep 2
  done
  echo ">>> $name is online."
}

# Extract AtoM if not already present
if [ ! -f "${ATOM_DIR}/symfony" ]; then
  echo ">>> Extracting AtoM ${ATOM_VERSION}..."
  mkdir -p /usr/share/nginx
  tar -xf "${TARBALL}" -C /usr/share/nginx/
  mkdir -p "${ATOM_DIR}"
  shopt -s dotglob nullglob
  mv /usr/share/nginx/atom-${ATOM_VERSION}/* "${ATOM_DIR}/" || true
  shopt -u dotglob nullglob
  rm -rf "/usr/share/nginx/atom-${ATOM_VERSION}"

  # Ensure correct permissions for log/cache dirs
  echo ">>> Creating and fixing permissions for log/cache dirs..."
  mkdir -p "${ATOM_DIR}/log" "${ATOM_DIR}/cache"
  chown -R www-data:www-data "${ATOM_DIR}/log" "${ATOM_DIR}/cache"
  chmod -R 775 "${ATOM_DIR}/log" "${ATOM_DIR}/cache"

  # Ensure correct permissions for uploads
  echo ">>> Creating and fixing permissions for uploads..."
  mkdir -p "${ATOM_DIR}/web/uploads/atom/objects"
  chown -R www-data:www-data "${ATOM_DIR}/web/uploads"
  chmod -R 775 "${ATOM_DIR}/web/uploads"
fi

# ----------------------
# GENERATE databases.yml
# ----------------------
echo ">>> Writing config/databases.yml..."
cat > "${ATOM_DIR}/config/databases.yml" <<EOF
all:
  default: propel
  propel:
    class: sfPropelDatabase
    param:
      dsn: "mysql:host=mysql;port=3306;dbname=atom"
      username: atom
      password: atompass
      encoding: utf8
      persistent: true
      pooling: true
      attributes:
        1007: true     # PDO::MYSQL_ATTR_USE_BUFFERED_QUERY
        1017: ""       # PDO::MYSQL_ATTR_UNIX_SOCKET (set to empty string to disable Unix socket)
EOF

# Clear symfony cache
echo ">>> Clearing Symfony cache after writing databases.yml..."
php "${ATOM_DIR}/symfony" cc


# ----------------------
# CONFIGURE MEMCACHED
# ----------------------
# Source the Memcached configuration script
# . /init/configure-memcached.sh
# configure_memcached
# validate_memcached_config

echo ">>> Fixing permissions for AtoM directory..."
chown -R www-data:www-data "${ATOM_DIR}"

cd "${ATOM_DIR}"

# Wait for dependent services
retry_until_success "MySQL"         "mysql -h\"${DB_HOST}\" -u\"${DB_USER}\" -p\"${DB_PASS}\" -e 'SELECT 1'"
retry_until_success "Elasticsearch" "curl -s http://${ES_HOST}:${ES_PORT}/"
retry_until_success "Gearman"       "nc -z ${GEARMAND_HOST} 4730"
# wait_for_memcached ${MAX_RETRIES}
# check_memcached_version

# Check if the AtoM database is initialized
echo ">>> Checking if AtoM database is initialized..."
if ! mysql -h"${DB_HOST}" -u"${DB_USER}" -p"${DB_PASS}" -D"${DB_NAME}" -e "SHOW TABLES LIKE 'object';" | grep -q "object"; then
  echo ">>> AtoM database not initialized. Running tools:install..."
  php -d memory_limit=2G symfony tools:install \
    --no-confirmation \
    --database-host="${DB_HOST}" \
    --database-port="3306" \
    --database-name="${DB_NAME}" \
    --database-user="${DB_USER}" \
    --database-password="${DB_PASS}" \
    --search-host="${ES_HOST}" \
    --search-port="${ES_PORT}" \
    --search-index="atom" \
    --site-title="${SITE_TITLE:-Access to Memory}" \
    --site-description="${SITE_DESCRIPTION:-AtoM archival platform}" \
    --site-base-url="${SITE_URL}" \
    --admin-email="${ADMIN_EMAIL:-admin@example.com}" \
    --admin-username="admin" \
    --admin-password="${ADMIN_PASS:-admin}"

  echo ">>> Warming Symfony cache..."
  php symfony cache:clear
  php symfony cc
else
  echo ">>> AtoM database already initialized."
fi

# Check if Elasticsearch index exists
if ! curl -s "http://${ES_HOST}:${ES_PORT}/atom" | grep -q '"status" : 404'; then
  echo ">>> Elasticsearch index already exists."
else
  echo ">>> Elasticsearch index missing. Populating..."
  php symfony search:populate
fi

# Final checks
retry_until_success "Final MySQL ping"     "mysqladmin ping -h${DB_HOST} -u${DB_USER} -p${DB_PASS}"
retry_until_success "Final Elasticsearch"  "curl -s http://${ES_HOST}:${ES_PORT}/"

echo ">>> Starting main process: $@"
exec "$@" || bash