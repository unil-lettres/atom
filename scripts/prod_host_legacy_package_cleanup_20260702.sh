#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root on atom-archives.unil.ch." >&2
  exit 1
fi

if [[ "$(hostname -f 2>/dev/null || hostname)" != "atom-archives" ]]; then
  echo "Refusing to run: expected host atom-archives." >&2
  exit 1
fi

export COMPOSE_FILE=/home/deployer/atom/docker/docker-compose.prod.yml
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
evidence="/home/deployer/atom/host-package-cleanup-tenable-${stamp}.txt"

targets=(
  ffmpeg
  imagemagick
  imagemagick-6-common
  imagemagick-6.q16
  libmagickcore-6.q16-6
  libmagickcore-6.q16-6-extra
  libmagickwand-6.q16-6
  nodejs
  nodejs-doc
  elasticsearch
  openjdk-11-jre-headless
)

{
  echo "# AtoM host legacy package cleanup evidence"
  echo "timestamp=${stamp}"
  echo "host=$(hostname -f 2>/dev/null || hostname)"
  echo
  echo "## Docker stack before package cleanup"
  cd /home/deployer/atom
  docker compose ps
  echo
  echo "## Installed package versions"
  dpkg-query -W -f='${binary:Package}\t${Version}\t${Status}\n' \
    "${targets[@]}" fop node-less ca-certificates-java 2>/dev/null || true
  echo
  echo "## Manual package marks"
  apt-mark showmanual | grep -E '^(ffmpeg|imagemagick|nodejs|elasticsearch|openjdk-11-jre-headless)$' || true
  echo
  echo "## Purge simulation"
  apt-get -s purge "${targets[@]}"
} | tee "$evidence"

if [[ "${1:-}" != "--apply" ]]; then
  echo
  echo "Dry run only. Evidence written to: $evidence"
  echo "Review the purge simulation, confirm a VM snapshot/rollback plan, then rerun with --apply."
  exit 0
fi

echo
echo "Applying explicit purge list. Autoremove is intentionally not run in this step."
apt-get purge -y "${targets[@]}"

echo
echo "Post-cleanup Docker stack:"
cd /home/deployer/atom
docker compose ps

echo
echo "Post-cleanup production health:"
./scripts/prod_health_check.sh

echo
echo "Review remaining unused dependencies separately with:"
echo "  apt-get -s autoremove --purge"
