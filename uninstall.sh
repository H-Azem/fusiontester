#!/bin/sh
# Removes what install.sh put on a host.
#
#   sudo sh uninstall.sh                 # stop and remove, keep the data
#   sudo sh uninstall.sh --purge         # also delete the database and workspaces
#
# The default is deliberately conservative: the containers, the units and the
# scripts go, but the volumes stay, so a reinstall starts where the old one left
# off. Docker itself is never removed unless asked — another stack may be using it.
set -eu

INSTALL_DIR="${FUSION_DIR:-/opt/fusiontester}"
CONFIG_DIR=/etc/fusion
CONTROL_DIR=/srv/fusion-control
SDK_DIR=/root/android-sdk
PROJECT="${COMPOSE_PROJECT_NAME:-tester}"

PURGE=0
REMOVE_DOCKER=0
REMOVE_SDK=0
REMOVE_NGINX=0
ASSUME_YES=0

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
warn() { printf '\033[33m%s\033[0m\n' "$*" >&2; }

usage() {
  cat <<'USAGE'
Usage: uninstall.sh [options]

  --purge           also delete the volumes (the database, workspaces, caches)
  --remove-sdk      also delete /root/android-sdk
  --remove-docker   also uninstall Docker (leave it alone if anything else uses it)
  --remove-nginx    also remove the nginx site and its certificate
  --yes             ask nothing
USAGE
}

while [ $# -gt 0 ]; do
  case "$1" in
    --purge) PURGE=1; shift ;;
    --remove-sdk) REMOVE_SDK=1; shift ;;
    --remove-docker) REMOVE_DOCKER=1; shift ;;
    --remove-nginx) REMOVE_NGINX=1; shift ;;
    --yes|-y) ASSUME_YES=1; shift ;;
    --help|-h) usage; exit 0 ;;
    *) printf 'unknown option: %s (try --help)\n' "$1" >&2; exit 1 ;;
  esac
done

confirm() {
  if [ "$ASSUME_YES" = "1" ]; then return 0; fi
  printf '%s [y/N]: ' "$1" >&2
  read -r answer || true
  case "$answer" in y|Y|yes|YES) return 0 ;; *) return 1 ;; esac
}

[ "$(id -u)" = "0" ] || { printf 'Run this as root (sudo sh uninstall.sh).\n' >&2; exit 1; }

# ------------------------------------------------------------------- services --
say "Stopping the services"
for unit in fusion-ai-lane.service fusion-device-watch.service \
            fusion-device-watchdog.timer fusion-manual-cleanup.timer fusion-binderfs.service; do
  systemctl disable --now "$unit" >/dev/null 2>&1 || true
done
systemctl daemon-reload >/dev/null 2>&1 || true

# ------------------------------------------------------------------ containers --
if [ -f "$INSTALL_DIR/docker-compose.yml" ]; then
  say "Stopping the application"
  (cd "$INSTALL_DIR" && docker compose -f docker-compose.yml down --remove-orphans) || true
  (cd "$INSTALL_DIR" && docker compose -f ops/host/redroid.yml down) || true
else
  warn "$INSTALL_DIR has no compose file; removing the containers by name instead."
  docker rm -f tester-api-1 tester-web-1 redroid-tester >/dev/null 2>&1 || true
fi

# ---------------------------------------------------------------------- units --
say "Removing the unit files and helper scripts"
rm -f /etc/systemd/system/fusion-ai-lane.service \
      /etc/systemd/system/fusion-binderfs.service \
      /etc/systemd/system/fusion-device-watch.service \
      /etc/systemd/system/fusion-device-watchdog.service \
      /etc/systemd/system/fusion-device-watchdog.timer \
      /etc/systemd/system/fusion-manual-cleanup.service \
      /etc/systemd/system/fusion-manual-cleanup.timer
rm -f /usr/local/bin/fusion-ai-lane.sh \
      /usr/local/bin/fusion-binderfs.sh \
      /usr/local/bin/fusion-device-watch.sh \
      /usr/local/bin/fusion-device-watchdog.sh \
      /usr/local/bin/fusion-manual-cleanup.sh
systemctl daemon-reload >/dev/null 2>&1 || true

rm -rf "$CONFIG_DIR" "$CONTROL_DIR"

# ----------------------------------------------------------------------- data --
if [ "$PURGE" = "1" ]; then
  say "Deleting the data (this cannot be undone)"
  if confirm "Delete the database, run workspaces and every cache of project '$PROJECT'?"; then
    docker volume ls --format '{{.Name}}' | grep -E "^(${PROJECT}_|fusiontester_)" | while read -r volume; do
      docker volume rm "$volume" >/dev/null 2>&1 && echo "removed volume $volume"
    done
    docker volume rm redroid-data >/dev/null 2>&1 || true
  fi
else
  say "Keeping the data"
  printf 'Volumes left in place (the database is one of them):\n'
  docker volume ls --format '{{.Name}}' | grep -E "^(${PROJECT}_|fusiontester_)" | sed 's/^/  /' || true
  printf 'Delete them later with: sudo sh uninstall.sh --purge\n'
fi

# --------------------------------------------------------------------- images --
say "Removing the images this project pulled or built"
docker rmi -f \
  ghcr.io/h-azem/tester-api:latest \
  ghcr.io/h-azem/tester-web:latest \
  ghcr.io/h-azem/tester-ai-tester:latest \
  tester-api:latest tester-web:latest tester-ai-tester:latest \
  >/dev/null 2>&1 || true
docker image prune -f >/dev/null 2>&1 || true

# ------------------------------------------------------------------- optional --
if [ "$REMOVE_SDK" = "1" ]; then
  say "Removing the Android SDK"
  rm -rf "$SDK_DIR" /usr/local/bin/adb
fi

if [ "$REMOVE_NGINX" = "1" ]; then
  say "Removing the nginx site"
  DOMAIN="$(sed -n 's/^WEB_ORIGIN=https\?:\/\///p' "$INSTALL_DIR/.env" 2>/dev/null | head -1 || true)"
  rm -f /etc/nginx/sites-enabled/fusion-tester /etc/nginx/sites-available/fusion-tester
  nginx -t >/dev/null 2>&1 && systemctl reload nginx >/dev/null 2>&1 || true
  if [ -n "$DOMAIN" ]; then
    certbot delete --cert-name "$DOMAIN" --non-interactive >/dev/null 2>&1 || true
  fi
fi

if [ "$REMOVE_DOCKER" = "1" ]; then
  say "Removing Docker"
  if docker ps -q | grep -q . ; then
    warn "Other containers are running; leaving Docker in place."
  elif confirm "Uninstall Docker itself?"; then
    systemctl disable --now docker >/dev/null 2>&1 || true
    apt-get remove -y -qq docker-ce docker-ce-cli containerd.io docker-compose-plugin >/dev/null 2>&1 || true
  fi
fi

# -------------------------------------------------------------------- checkout --
if [ -d "$INSTALL_DIR" ]; then
  say "Removing $INSTALL_DIR"
  rm -rf "$INSTALL_DIR"
fi

say "Done"
printf 'Left in place: Docker itself'
[ "$REMOVE_SDK" = "1" ] || printf ', the Android SDK at %s' "$SDK_DIR"
[ "$PURGE" = "1" ] || printf ', and the data volumes'
printf '.\n'
