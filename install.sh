#!/bin/sh
# One-shot installer for Fusion Tester on Ubuntu.
#
#   curl -fsSL https://raw.githubusercontent.com/H-Azem/fusiontester/main/install.sh | sudo sh
#
# It does not need a clean server: it takes its own port, keeps every file it
# writes under one directory or /etc/fusion, and never touches another service.
# Running it again updates in place.
#
# What it brings up:
#   - Docker, if the host has none
#   - the app (api + web) from self-contained images: Flutter, a JDK, Maestro and
#     Chromium are inside them, so nothing is installed on the host for a build
#   - the AI lane image and the host-side runner that starts it
#   - the Android device (redroid) and the watchers that keep it alive
#   - optionally, nginx and a TLS certificate for a domain
set -eu

REPO_URL="${FUSION_REPO:-https://github.com/H-Azem/fusiontester.git}"
BRANCH="${FUSION_BRANCH:-main}"
INSTALL_DIR="${FUSION_DIR:-/opt/fusiontester}"
CONFIG_DIR=/etc/fusion
LANE_IMAGE="${FUSION_AI_IMAGE:-ghcr.io/h-azem/tester-ai-tester:latest}"
DEVICE_CONTAINER=redroid-tester
DEFAULT_WEB_PORT=1999
DEFAULT_DEVICE_PORT=5555

ASSUME_YES=0
WITH_DEVICE=""
WITH_TLS=""
DOMAIN=""
ADMIN_PASSWORD=""

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
warn() { printf '\033[33m%s\033[0m\n' "$*" >&2; }
die()  { printf '\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

usage() {
  cat <<'USAGE'
Usage: install.sh [options]

  --dir <path>          where to keep the checkout (default /opt/fusiontester)
  --web-port <port>     host port for the dashboard (default 1999, or the next free)
  --device-port <port>  host port for the Android device's adb (default 5555)
  --domain <name>       set up nginx and a TLS certificate for this domain
  --no-device           skip the Android device entirely (web lane only)
  --no-tls              never touch nginx, whatever else is asked
  --password <value>    admin password (default: generated and printed)
  --yes                 take every default, ask nothing
USAGE
}

# ------------------------------------------------------------------ arguments --
while [ $# -gt 0 ]; do
  case "$1" in
    --dir) INSTALL_DIR="$2"; shift 2 ;;
    --web-port) DEFAULT_WEB_PORT="$2"; shift 2 ;;
    --device-port) DEFAULT_DEVICE_PORT="$2"; shift 2 ;;
    --domain) DOMAIN="$2"; WITH_TLS=1; shift 2 ;;
    --no-device) WITH_DEVICE=0; shift ;;
    --no-tls) WITH_TLS=0; shift ;;
    --password) ADMIN_PASSWORD="$2"; shift 2 ;;
    --yes|-y) ASSUME_YES=1; shift ;;
    --help|-h) usage; exit 0 ;;
    *) die "unknown option: $1 (try --help)" ;;
  esac
done

# Prompts are read from the terminal, never from stdin. Run as `curl … | sh`, stdin
# *is* this script, so reading it there swallows the script's own next lines — which
# is exactly how a warning once became the dashboard's port number.
read_answer() {
  if [ -r /dev/tty ]; then
    read -r answer < /dev/tty || answer=""
  else
    answer=""
  fi
}

ask() {
  # $1 = question, $2 = default
  if [ "$ASSUME_YES" = "1" ]; then printf '%s' "$2"; return; fi
  printf '%s [%s]: ' "$1" "$2" >&2
  read_answer
  printf '%s' "${answer:-$2}"
}

ask_port() {
  # $1 = question, $2 = default — a port has to be a port.
  value="$(ask "$1" "$2")"

  case "$value" in
    '' | *[!0-9]*)
      warn "\"$value\" is not a port number; using $2."
      value="$2"
      ;;
  esac

  printf '%s' "$value"
}

confirm() {
  # $1 = question, $2 = default (y/n)
  if [ "$ASSUME_YES" = "1" ]; then [ "$2" = "y" ]; return; fi
  printf '%s [%s]: ' "$1" "$2" >&2
  read_answer
  case "${answer:-$2}" in y|Y|yes|YES) return 0 ;; *) return 1 ;; esac
}

port_is_free() {
  ! ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]$1\$"
}

next_free_port() {
  port="$1"
  while [ "$port" -lt 65535 ] && ! port_is_free "$port"; do
    port=$((port + 1))
  done
  printf '%s' "$port"
}

# ------------------------------------------------------------------ preflight --
say "Checking the host"

[ "$(id -u)" = "0" ] || die "Run this as root (sudo sh install.sh ...)."
command -v apt-get >/dev/null 2>&1 || die "This installer is for Ubuntu/Debian hosts."
[ -d /run/systemd/system ] || die "systemd is required (it keeps the device and the lane running)."

if ! command -v docker >/dev/null 2>&1; then
  say "Installing Docker"
  curl -fsSL https://get.docker.com | sh
  systemctl enable --now docker
fi

docker info >/dev/null 2>&1 || die "Docker is installed but not running."

# unzip is needed to unpack the SDK tools, and `java`: the Android SDK manager is
# a Java program, and a fresh host has no JVM — the JDK the images carry is inside
# them, not on the host.
for tool in git curl ss openssl unzip java; do
  command -v "$tool" >/dev/null 2>&1 || {
    apt-get update -qq
    apt-get install -y -qq git curl iproute2 openssl unzip default-jre-headless
    break
  }
done

# --------------------------------------------------------------------- prompts --
if [ -z "$WITH_DEVICE" ]; then
  if confirm "Set up the Android device lane as well (needs a kernel with binderfs)?" y
  then WITH_DEVICE=1; else WITH_DEVICE=0; fi
fi

if [ -z "$WITH_TLS" ]; then
  if confirm "Put it behind nginx with a TLS certificate?" n; then
    WITH_TLS=1
    DOMAIN="$(ask "Domain name pointing at this server" "${DOMAIN:-tester.example.com}")"
  else
    WITH_TLS=0
  fi
fi

WEB_PORT="$(ask_port "Host port for the dashboard" "$DEFAULT_WEB_PORT")"
if ! port_is_free "$WEB_PORT"; then
  FREE="$(next_free_port "$WEB_PORT")"
  warn "Port $WEB_PORT is already in use; using $FREE instead."
  WEB_PORT="$FREE"
fi

if [ "$WITH_DEVICE" = "1" ]; then
  DEVICE_PORT="$(ask_port "Host port for the device's adb" "$DEFAULT_DEVICE_PORT")"
  if ! port_is_free "$DEVICE_PORT"; then
    FREE="$(next_free_port "$DEVICE_PORT")"
    warn "Port $DEVICE_PORT is already in use; using $FREE instead."
    DEVICE_PORT="$FREE"
  fi
else
  DEVICE_PORT="$DEFAULT_DEVICE_PORT"
fi

if [ -z "$ADMIN_PASSWORD" ]; then
  ADMIN_PASSWORD="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-16)"
  GENERATED_PASSWORD=1
fi

# ------------------------------------------------------------------------ code --
say "Fetching the project into $INSTALL_DIR"

if [ -d "$INSTALL_DIR/.git" ]; then
  git -C "$INSTALL_DIR" fetch --quiet origin "$BRANCH"
  git -C "$INSTALL_DIR" checkout --quiet "$BRANCH"
  git -C "$INSTALL_DIR" pull --quiet --ff-only
else
  git clone --quiet --branch "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
fi

cd "$INSTALL_DIR"

# ------------------------------------------------------------------------ env --
# The origin is compared against on every login, so it has to be the URL people
# actually open — the domain when there is one, the address otherwise.
HOST_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
if [ "$WITH_TLS" = "1" ]; then
  WEB_ORIGIN="https://${DOMAIN}"
else
  WEB_ORIGIN="http://${HOST_IP:-localhost}:${WEB_PORT}"
fi

if [ ! -f .env ]; then
  say "Writing .env"
  APP_KEY="base64:$(openssl rand -base64 32)"
  cat > .env <<ENV
COMPOSE_PROJECT_NAME=tester
APP_KEY=${APP_KEY}
WEB_ORIGIN=${WEB_ORIGIN}
ADMIN_USERNAME=fusion
ADMIN_PASSWORD=${ADMIN_PASSWORD}
WEB_PORT=${WEB_PORT}
ANDROID_DEVICE=172.17.0.1:${DEVICE_PORT}
ANDROID_SDK_PATH=/root/android-sdk
FUSION_CONTROL_PATH=/srv/fusion-control
ENV
  chmod 600 .env
else
  say "Keeping the existing .env"

  # A port that is not a port (an older run could write one) would leave Docker
  # refusing to start with a message about the compose file rather than the value.
  stored_port="$(sed -n 's/^WEB_PORT=//p' .env | head -1 || true)"

  case "$stored_port" in
    '' | *[!0-9]*)
      warn "The .env holds \"${stored_port}\" as WEB_PORT; rewriting it as ${WEB_PORT}."
      sed -i "s|^WEB_PORT=.*|WEB_PORT=${WEB_PORT}|" .env
      ;;
    *) WEB_PORT="$stored_port" ;;
  esac

  stored_device="$(sed -n 's/^ANDROID_DEVICE=.*://p' .env | head -1 || true)"

  case "$stored_device" in
    '' | *[!0-9]*) DEVICE_PORT="$DEVICE_PORT" ;;
    *) DEVICE_PORT="$stored_device" ;;
  esac
fi

# ---------------------------------------------------------------- android sdk --
# The api image carries Flutter, a JDK, Maestro and Chromium, but the Android SDK
# stays on the host (it is several gigabytes and is mounted in). Without it the
# web lane works and an APK build does not — so a failure here is a warning, never
# the end of the install.
install_android_sdk() {
  set +e

  mkdir -p /root/android-sdk/cmdline-tools
  curl -fsSL -o /tmp/cmdline-tools.zip \
    https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip || return 1
  unzip -q -o /tmp/cmdline-tools.zip -d /tmp/cmdline-tools || return 1
  rm -rf /root/android-sdk/cmdline-tools/latest
  mv /tmp/cmdline-tools/cmdline-tools /root/android-sdk/cmdline-tools/latest || return 1
  rm -rf /tmp/cmdline-tools /tmp/cmdline-tools.zip

  SDKMANAGER=/root/android-sdk/cmdline-tools/latest/bin/sdkmanager
  yes | "$SDKMANAGER" --licenses >/dev/null 2>&1
  "$SDKMANAGER" --install "platform-tools" "platforms;android-35" "build-tools;35.0.0" >/dev/null || return 1

  ln -sf /root/android-sdk/platform-tools/adb /usr/local/bin/adb
  return 0
}

if [ "$WITH_DEVICE" = "1" ] && [ ! -d /root/android-sdk/platform-tools ]; then
  say "Installing the Android SDK into /root/android-sdk (a few gigabytes)"

  if install_android_sdk; then
    printf 'The Android SDK is ready.\n'
  else
    warn "The Android SDK could not be installed. The dashboard and the web lane still work;"
    warn "an APK build needs it. Retry with: sudo sh install.sh"
  fi
fi

# ----------------------------------------------------------------------- app --
# The images are built by CI, so a deploy is a download. Building here is the
# fallback: it is the same image, but it fetches Flutter, a JDK, Maestro and
# Chromium first — several gigabytes and a long wait.
say "Getting the application images"
if docker compose -f docker-compose.yml pull --quiet; then
  say "Starting the app"
  docker compose -f docker-compose.yml up -d
else
  warn "Could not pull the published images; building them here instead (this takes a while)."
  docker compose -f docker-compose.yml up -d --build
fi

# ------------------------------------------------------------------ ai lane --
say "Installing the AI lane"
docker pull "$LANE_IMAGE" >/dev/null 2>&1 || warn "Could not pull $LANE_IMAGE; the lane will not run."
docker tag "$LANE_IMAGE" tester-ai-tester:latest

install -m 755 apps/ai-tester/runner/fusion-ai-lane.sh /usr/local/bin/fusion-ai-lane.sh
install -m 644 apps/ai-tester/runner/fusion-ai-lane.service /etc/systemd/system/fusion-ai-lane.service

# -------------------------------------------------------------- the device --
if [ "$WITH_DEVICE" = "1" ]; then
  say "Bringing up the Android device"

  mkdir -p /srv/fusion-control "$CONFIG_DIR"
  cat > "$CONFIG_DIR/fusion.env" <<ENV
FUSION_CONTROL_DIR=/srv/fusion-control
FUSION_DEVICE_CONTAINER=${DEVICE_CONTAINER}
FUSION_DEVICE=172.17.0.1:${DEVICE_PORT}
FUSION_ADB=/root/android-sdk/platform-tools/adb
ENV
  chmod 600 "$CONFIG_DIR/fusion.env"

  install -m 755 ops/host/fusion-binderfs.sh /usr/local/bin/fusion-binderfs.sh
  install -m 755 ops/host/fusion-device-watch.sh /usr/local/bin/fusion-device-watch.sh
  install -m 755 ops/host/fusion-device-watchdog.sh /usr/local/bin/fusion-device-watchdog.sh
  install -m 755 ops/host/fusion-manual-cleanup.sh /usr/local/bin/fusion-manual-cleanup.sh
  install -m 644 ops/host/systemd/*.service ops/host/systemd/*.timer /etc/systemd/system/

  /usr/local/bin/fusion-binderfs.sh || warn "binderfs could not be mounted; the device may not start."

  FUSION_DEVICE_PORT="$DEVICE_PORT" FUSION_DEVICE_CONTAINER="$DEVICE_CONTAINER" \
    docker compose -f ops/host/redroid.yml up -d

  systemctl daemon-reload
  systemctl enable --now fusion-binderfs.service
  systemctl enable --now fusion-device-watch.service
  systemctl enable --now fusion-device-watchdog.timer
  systemctl enable --now fusion-manual-cleanup.timer
fi

systemctl daemon-reload
systemctl enable --now fusion-ai-lane.service

# ------------------------------------------------------------------------- tls --
if [ "$WITH_TLS" = "1" ]; then
  say "Setting up nginx and a certificate for $DOMAIN"
  apt-get update -qq
  apt-get install -y -qq nginx certbot python3-certbot-nginx

  cat > "/etc/nginx/sites-available/fusion-tester" <<NGINX
server {
    listen 80;
    server_name ${DOMAIN};
    location / {
        proxy_pass http://127.0.0.1:${WEB_PORT};
        proxy_set_header Host              \$host;
        proxy_set_header X-Real-IP         \$remote_addr;
        proxy_set_header X-Forwarded-For   \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX

  ln -sf /etc/nginx/sites-available/fusion-tester /etc/nginx/sites-enabled/fusion-tester
  nginx -t && systemctl reload nginx
  certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email || \
    warn "certbot did not finish; the site is served over plain HTTP for now."
fi

# --------------------------------------------------------------------- report --
say "Done"
HOST_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
if [ "$WITH_TLS" = "1" ]; then
  printf 'Dashboard : https://%s\n' "$DOMAIN"
else
  printf 'Dashboard : http://%s:%s\n' "${HOST_IP:-<server>}" "$WEB_PORT"
fi
printf 'Admin     : fusion / %s\n' "$ADMIN_PASSWORD"
[ "${GENERATED_PASSWORD:-0}" = "1" ] && printf '            (generated — change it after the first sign-in)\n'
printf 'Next      : sign in, open Settings, then\n'
printf '            - GitLab/Gitea + token\n'
printf '            - the AI model endpoint and its key\n'
printf '            - Telegram, if reports should go to a channel\n'
[ "$WITH_DEVICE" = "1" ] && printf 'Device    : adb at 172.17.0.1:%s (watch /srv/fusion-control/device.log)\n' "$DEVICE_PORT"
printf 'Logs      : cd %s && docker compose logs -f api\n' "$INSTALL_DIR"
