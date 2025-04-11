#!/usr/bin/env bash
# Script to configure Memcached for AtoM

# Set default values if not provided
MEMCACHED_HOST="${MEMCACHED_HOST:-memcached}"
MEMCACHED_PORT="${MEMCACHED_PORT:-11211}"
ATOM_DIR="${ATOM_DIR:-/usr/share/nginx/atom}"

configure_memcached() {
  echo ">>> Configuring Memcached in app.yml..."
  if [ -f "${ATOM_DIR}/config/app.yml" ]; then
    # Check if already using sfMemcacheCache
    if grep -q "cache_engine: sfMemcacheCache" "${ATOM_DIR}/config/app.yml"; then
      echo ">>> Memcached already configured as cache engine."
    else
      echo ">>> Updating app.yml to use Memcached..."
      # Backup the original file
      cp "${ATOM_DIR}/config/app.yml" "${ATOM_DIR}/config/app.yml.bak"
      
      # Replace cache_engine line
      sed -i 's/cache_engine: sfAPCCache/cache_engine: sfMemcacheCache/' "${ATOM_DIR}/config/app.yml"
      
      # Add Memcached parameters if not present
      if ! grep -q "cache:" "${ATOM_DIR}/config/app.yml"; then
        # Find the line after cache_engine and insert cache parameters
        sed -i "/cache_engine: sfMemcacheCache/a \  cache:\n    param:\n      host: ${MEMCACHED_HOST}\n      port: ${MEMCACHED_PORT}" "${ATOM_DIR}/config/app.yml"
      fi
      
      echo ">>> Successfully updated app.yml to use Memcached."
    fi
  else
    echo ">>> app.yml not found. Creating template for installation..."
    # Create a temp config to be used during the tools:install process
    mkdir -p "${ATOM_DIR}/config"
    cat > "${ATOM_DIR}/config/app.yml.template" << EOF
all:
  upload_limit: -1
  download_timeout: 10
  cache_engine: sfMemcacheCache
  cache:
    param:
      host: ${MEMCACHED_HOST}
      port: ${MEMCACHED_PORT}
  read_only: false
  htmlpurifier_enabled: false
  workers_key:
  password_hash_algorithm: PASSWORD_ARGON2I
  password_hash_algorithm_options: {"memory_cost": "2048", "time_cost": "4", "threads": "3"}
EOF
  fi
}

validate_memcached_config() {
  echo ">>> Checking app.yml for Memcached config..."

  if [ -f "${ATOM_DIR}/config/app.yml" ]; then
    # Check for cache engine type
    if grep -q "cache_engine:.*sfMemcacheCache" "${ATOM_DIR}/config/app.yml" 2>/dev/null; then
      # Check for host and port (more flexible pattern matching)
      if grep -q "host:.*${MEMCACHED_HOST}" "${ATOM_DIR}/config/app.yml" && \
         grep -q "port:.*${MEMCACHED_PORT}" "${ATOM_DIR}/config/app.yml"; then
        echo ">>> AtoM configured correctly to use Memcached at ${MEMCACHED_HOST}:${MEMCACHED_PORT}."
        
        # Validate connectivity
        if timeout 2 bash -c "</dev/tcp/${MEMCACHED_HOST}/${MEMCACHED_PORT}" >/dev/null 2>&1; then
          echo ">>> Connection to Memcached successful."
        else
          echo ">>> WARNING: Cannot connect to Memcached at ${MEMCACHED_HOST}:${MEMCACHED_PORT}"
        fi
      else
        echo ">>> WARNING: Memcached configuration in app.yml may be incomplete."
        echo ">>>          Expected configuration with host: ${MEMCACHED_HOST}, port: ${MEMCACHED_PORT}"
        
        # Additional validation to check actual values
        CONFIGURED_HOST=$(grep -A 5 "host:" "${ATOM_DIR}/config/app.yml" | head -1 | sed -E 's/.*host:[ \t]*([^ \t]*)[ \t]*.*/\1/')
        CONFIGURED_PORT=$(grep -A 5 "port:" "${ATOM_DIR}/config/app.yml" | head -1 | sed -E 's/.*port:[ \t]*([0-9]+)[ \t]*.*/\1/')
        
        if [ -n "$CONFIGURED_HOST" ] && [ -n "$CONFIGURED_PORT" ]; then
          echo ">>>          Found configuration with host: ${CONFIGURED_HOST}, port: ${CONFIGURED_PORT}"
          if timeout 2 bash -c "</dev/tcp/${CONFIGURED_HOST}/${CONFIGURED_PORT}" >/dev/null 2>&1; then
            echo ">>>          Connection to configured Memcached successful."
          else
            echo ">>>          ERROR: Cannot connect to configured Memcached at ${CONFIGURED_HOST}:${CONFIGURED_PORT}"
          fi
        fi
      fi
    else
      echo ">>> WARNING: AtoM not configured to use Memcached (missing sfMemcacheCache in app.yml)"
      echo ">>>          This may impact application performance."
    fi
  else
    echo ">>> WARNING: app.yml not found at ${ATOM_DIR}/config/app.yml"
  fi
}

check_memcached_version() {
  echo ">>> Checking Memcached version:"
  if timeout 2 bash -c "printf 'version\r\n' | nc ${MEMCACHED_HOST} ${MEMCACHED_PORT} | grep -q '^VERSION '" ; then
    echo ">>> Memcached connection succeeded."
  else
    echo ">>> WARNING: Memcached not responding"
  fi
}

wait_for_memcached() {
  local i=0 max=${1:-60}
  echo ">>> Waiting for Memcached to be reachable..."
  until timeout 2 bash -c "</dev/tcp/${MEMCACHED_HOST}/${MEMCACHED_PORT}" >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge "$max" ]; then
      echo "ERROR: Timeout while waiting for Memcached."
      return 1
    fi
    echo "Memcached is not online yet; waiting 2 seconds..."
    sleep 2
  done
  echo ">>> Memcached is online."
  return 0
}

# Execute functions if script is run directly (not sourced)
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  configure_memcached
  validate_memcached_config
  wait_for_memcached
  check_memcached_version
fi