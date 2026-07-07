#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  scripts/atom_db_schema_snapshot.sh [--mode compose|mysql] [--env-file PATH]
                                     [--compose-service NAME]
                                     [--db NAME] [--user USER] [--pass PASS]
                                     [--host HOST] [--port PORT]

Outputs a deterministic TSV snapshot of:
  - columns (INFORMATION_SCHEMA.COLUMNS)
  - indexes (INFORMATION_SCHEMA.STATISTICS)
  - foreign keys (INFORMATION_SCHEMA.KEY_COLUMN_USAGE)

Defaults:
  - If --env-file is omitted and docker/etc/environment exists, it is loaded.
  - --mode defaults to compose.
  - --compose-service defaults to percona.

Examples:
  # Local docker (preferred): uses docker compose exec into percona
  export COMPOSE_FILE="$PWD/docker/docker-compose.dev.yml:$PWD/docker/docker-compose.override.arm.yml"
  scripts/atom_db_schema_snapshot.sh --mode compose > /tmp/atom-schema.local.tsv

  # Direct MySQL (e.g. prod host): requires mysql client installed
  scripts/atom_db_schema_snapshot.sh --mode mysql --host 127.0.0.1 --port 3306 \
    --db atom --user atom --pass 'secret' > /tmp/atom-schema.prod.tsv
EOF
}

mode="compose"
env_file=""
compose_service="percona"

db="${MYSQL_DATABASE:-}"
user="${MYSQL_USER:-}"
pass="${MYSQL_PASSWORD:-}"
host="127.0.0.1"
port="3306"

while [[ $# -gt 0 ]]; do
  case "$1" in
    -h|--help)
      usage
      exit 0
      ;;
    --mode)
      mode="${2:-}"; shift 2
      ;;
    --env-file)
      env_file="${2:-}"; shift 2
      ;;
    --compose-service)
      compose_service="${2:-}"; shift 2
      ;;
    --db)
      db="${2:-}"; shift 2
      ;;
    --user)
      user="${2:-}"; shift 2
      ;;
    --pass)
      pass="${2:-}"; shift 2
      ;;
    --host)
      host="${2:-}"; shift 2
      ;;
    --port)
      port="${2:-}"; shift 2
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ -z "$env_file" && -f "docker/etc/environment" ]]; then
  env_file="docker/etc/environment"
fi

if [[ -n "$env_file" ]]; then
  # shellcheck disable=SC1090
  set -a
  source "$env_file"
  set +a
fi

db="${db:-${MYSQL_DATABASE:-}}"
user="${user:-${MYSQL_USER:-}}"
pass="${pass:-${MYSQL_PASSWORD:-}}"

if [[ -z "$db" || -z "$user" || -z "$pass" ]]; then
  echo "Missing connection params. Need db/user/pass (set via --db/--user/--pass or env file)." >&2
  exit 2
fi

run_mysql() {
  local sql="$1"

  if [[ "$mode" == "compose" ]]; then
    docker compose exec -T "$compose_service" \
      mysql --batch --raw --silent --skip-column-names \
        -u"$user" -p"$pass" "$db" -e "$sql"
    return
  fi

  if [[ "$mode" == "mysql" ]]; then
    mysql --protocol=tcp --batch --raw --silent --skip-column-names \
      -h "$host" -P "$port" -u"$user" -p"$pass" "$db" -e "$sql"
    return
  fi

  echo "Invalid --mode: $mode (expected: compose|mysql)" >&2
  exit 2
}

generated_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

echo "# atom_db_schema_snapshot v1"
echo "# generated_at_utc\t${generated_at}"
echo -e "# mode\t${mode}"
if [[ "$mode" == "compose" ]]; then
  echo -e "# compose_service\t${compose_service}"
else
  echo -e "# host\t${host}"
  echo -e "# port\t${port}"
fi
echo -e "# db\t${db}"
echo

echo "# columns"
echo -e "table\tcolumn\tcolumn_type\tis_nullable\tdefault\textra\tcollation\tcolumn_key"
run_mysql "
  SELECT
    TABLE_NAME,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    IFNULL(COLUMN_DEFAULT, '<NULL>'),
    IFNULL(EXTRA, ''),
    IFNULL(COLLATION_NAME, ''),
    IFNULL(COLUMN_KEY, '')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
  ORDER BY TABLE_NAME, ORDINAL_POSITION;
"
echo

echo "# indexes"
echo -e "table\tindex_name\tnon_unique\tseq_in_index\tcolumn\tcollation\tsub_part\tindex_type"
run_mysql "
  SELECT
    TABLE_NAME,
    INDEX_NAME,
    NON_UNIQUE,
    SEQ_IN_INDEX,
    COLUMN_NAME,
    IFNULL(COLLATION, ''),
    IFNULL(SUB_PART, ''),
    IFNULL(INDEX_TYPE, '')
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
  ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
"
echo

echo "# foreign_keys"
echo -e "table\tconstraint\tcolumn\treferenced_table\treferenced_column"
run_mysql "
  SELECT
    TABLE_NAME,
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND REFERENCED_TABLE_NAME IS NOT NULL
  ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION;
"

