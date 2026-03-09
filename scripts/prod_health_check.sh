#!/usr/bin/env bash
set -euo pipefail

BASE="${ATOM_BASE:-/home/deployer/atom}"
COMPOSE_FILE_DEFAULT="$BASE/docker/docker-compose.prod.yml"
export COMPOSE_FILE="${COMPOSE_FILE:-$COMPOSE_FILE_DEFAULT}"
ATOM_URL="${ATOM_URL:-https://atom-archives.unil.ch}"
DUMPS_DIR="${ATOM_DUMPS_DIR:-/home/deployer/dumps}"
LOOKBACK="${LOOKBACK:-24h}"

fail_count=0
warn_count=0

info() { printf '[INFO] %s\n' "$*"; }
warn() { printf '[WARN] %s\n' "$*"; warn_count=$((warn_count + 1)); }
fail() { printf '[FAIL] %s\n' "$*"; fail_count=$((fail_count + 1)); }

info "Using COMPOSE_FILE=$COMPOSE_FILE"
info "Target URL=$ATOM_URL"
info "Dumps dir=$DUMPS_DIR"

if ! docker compose -f "$COMPOSE_FILE" ps >/dev/null 2>&1; then
  fail "docker compose unavailable with $COMPOSE_FILE"
else
  expected_services=(atom atom_worker nginx percona elasticsearch memcached gearmand)
  running_services="$(docker compose -f "$COMPOSE_FILE" ps --status running --services 2>/dev/null || true)"
  for svc in "${expected_services[@]}"; do
    if printf '%s\n' "$running_services" | grep -qx "$svc"; then
      info "service running: $svc"
    else
      fail "service not running: $svc"
    fi
  done
fi

tmp_home="$(mktemp)"
http_code="$(curl -sL -o "$tmp_home" -w '%{http_code}' "$ATOM_URL" || true)"
if [[ "$http_code" == "200" ]]; then
  info "home status: 200"
else
  fail "home status unexpected: $http_code"
fi
if grep -q '<html lang="fr"' "$tmp_home"; then
  info "home lang: fr"
else
  warn "home lang is not fr"
fi
rm -f "$tmp_home"

pm_children="$(docker compose -f "$COMPOSE_FILE" exec -T atom sh -lc "awk -F'= ' '/^pm.max_children/ {print \$2}' /usr/local/etc/php-fpm.d/atom.conf" 2>/dev/null || true)"
if [[ -n "$pm_children" ]]; then
  info "php-fpm pm.max_children: $pm_children"
else
  warn "cannot read php-fpm pm.max_children"
fi

max_children_hits="$(docker compose -f "$COMPOSE_FILE" logs --since="$LOOKBACK" atom 2>/dev/null | grep -c 'max_children setting' || true)"
if [[ "${max_children_hits:-0}" -gt 0 ]]; then
  warn "php-fpm saturation warnings in last $LOOKBACK: $max_children_hits"
else
  info "no php-fpm saturation warning in last $LOOKBACK"
fi

atom_container_id="$(docker compose -f "$COMPOSE_FILE" ps -q atom 2>/dev/null || true)"
if [[ -n "$atom_container_id" ]]; then
  atom_started_at="$(docker inspect -f '{{.State.StartedAt}}' "$atom_container_id" 2>/dev/null || true)"
  if [[ -n "$atom_started_at" ]]; then
    hits_since_start="$(docker compose -f "$COMPOSE_FILE" logs --since="$atom_started_at" atom 2>/dev/null | grep -c 'max_children setting' || true)"
    if [[ "${hits_since_start:-0}" -gt 0 ]]; then
      warn "php-fpm saturation warnings since atom start ($atom_started_at): $hits_since_start"
    else
      info "no php-fpm saturation warning since atom start ($atom_started_at)"
    fi
  else
    warn "cannot read atom container start time"
  fi
else
  warn "cannot resolve atom container id"
fi

latest_dump="$(ls -1t "$DUMPS_DIR"/atom-percona-*.sql.gz 2>/dev/null | head -n 1 || true)"
if [[ -z "$latest_dump" ]]; then
  fail "no atom-percona dumps found in $DUMPS_DIR"
else
  now_epoch="$(date +%s)"
  dump_epoch="$(stat -c %Y "$latest_dump" 2>/dev/null || true)"
  if [[ -z "$dump_epoch" ]]; then
    warn "cannot read dump mtime: $latest_dump"
  else
    age_hours=$(( (now_epoch - dump_epoch) / 3600 ))
    info "latest dump: $(basename "$latest_dump") (age ${age_hours}h)"
    if [[ "$age_hours" -gt 30 ]]; then
      fail "latest dump too old (>30h)"
    fi
  fi
fi

if df_out="$(df -P "$DUMPS_DIR" 2>/dev/null | awk 'NR==2 {print $5, $4}')"; then
  usage_pct="$(printf '%s' "$df_out" | awk '{print $1}' | tr -d '%')"
  avail="$(printf '%s' "$df_out" | awk '{print $2}')"
  info "dumps disk usage: ${usage_pct}% (avail $avail)"
  if [[ "$usage_pct" -ge 90 ]]; then
    fail "dumps filesystem critically full (>=90%)"
  elif [[ "$usage_pct" -ge 80 ]]; then
    warn "dumps filesystem high usage (>=80%)"
  fi
else
  warn "cannot read dumps filesystem usage"
fi

legacy_count="$(find "$DUMPS_DIR" -maxdepth 1 -type f -name '*.atom.backup.sql' 2>/dev/null | wc -l | tr -d ' ')"
if [[ "${legacy_count:-0}" -gt 4 ]]; then
  warn "legacy rollback dumps count is high: $legacy_count"
else
  info "legacy rollback dumps count: ${legacy_count:-0}"
fi

printf '\nSummary: fails=%d warnings=%d\n' "$fail_count" "$warn_count"
if [[ "$fail_count" -gt 0 ]]; then
  exit 1
fi
