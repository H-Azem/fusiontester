#!/bin/sh
# The Flutter SDK the pipeline builds with.
#
# The image ships one at /opt/flutter. An updated SDK is kept on a volume at
# /sdk/flutter so it survives a redeploy; once it exists it is the one the
# pipeline uses, and the baked copy stays as the fallback a restore returns to.
#
#   flutter-sdk status
#   flutter-sdk update [<tag>]     # no tag = latest stable
#   flutter-sdk restore
set -eu

VOLUME_ROOT=/sdk
VOLUME_SDK="$VOLUME_ROOT/flutter"
BAKED_SDK=/opt/flutter
PREVIOUS="$VOLUME_ROOT/flutter-sdk.previous"
LOCK="$VOLUME_ROOT/flutter-sdk.lock"
STATE="$VOLUME_ROOT/flutter-sdk.state"

active_sdk() {
  if [ -x "$VOLUME_SDK/bin/flutter" ]; then
    echo "$VOLUME_SDK"
  else
    echo "$BAKED_SDK"
  fi
}

json_escape() {
  printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\n'
}

version_of() {
  "$1/bin/flutter" --version 2>/dev/null | head -1 | sed 's/^Flutter //' || true
}

revision_of() {
  git -C "$1" rev-parse HEAD 2>/dev/null || true
}

write_state() {
  # $1 = status ("ready" | "updating" | "failed"), $2 = message
  printf '{"status":"%s","message":"%s","at":"%s"}\n' \
    "$1" "$(json_escape "$2")" "$(date -Is)" > "$STATE"
}

status() {
  sdk=$(active_sdk)
  source_name=baked
  [ "$sdk" = "$VOLUME_SDK" ] && source_name=volume

  printf '{"path":"%s","source":"%s","version":"%s","revision":"%s","previousRevision":"%s","busy":%s,"state":%s}\n' \
    "$sdk" \
    "$source_name" \
    "$(json_escape "$(version_of "$sdk")")" \
    "$(revision_of "$sdk")" \
    "$(cat "$PREVIOUS" 2>/dev/null || true)" \
    "$([ -f "$LOCK" ] && echo true || echo false)" \
    "$(cat "$STATE" 2>/dev/null || echo 'null')"
}

# Copies the baked SDK onto the volume once, so an update never starts by
# downloading a second full copy over the network.
seed_volume() {
  if [ -x "$VOLUME_SDK/bin/flutter" ]; then
    return
  fi

  mkdir -p "$VOLUME_ROOT"
  rm -rf "$VOLUME_SDK"
  cp -a "$BAKED_SDK" "$VOLUME_SDK"
}

update() {
  target="${1:-}"

  if [ -f "$LOCK" ]; then
    write_state failed "An update is already running."
    return 1
  fi

  mkdir -p "$VOLUME_ROOT"
  : > "$LOCK"
  trap 'rm -f "$LOCK"' EXIT INT TERM

  write_state updating "Preparing the SDK on the volume…"
  seed_volume

  git config --global --add safe.directory "$VOLUME_SDK" >/dev/null 2>&1 || true
  git -C "$VOLUME_SDK" rev-parse HEAD > "$PREVIOUS" 2>/dev/null || true

  export PATH="$VOLUME_SDK/bin:$PATH"

  if [ -n "$target" ]; then
    write_state updating "Checking out Flutter $target…"
    git -C "$VOLUME_SDK" fetch --tags --depth=1 origin "$target" >/dev/null 2>&1 || true
    git -C "$VOLUME_SDK" checkout --force "$target" >/dev/null 2>&1
  else
    write_state updating "Upgrading to the latest stable Flutter…"
    git -C "$VOLUME_SDK" checkout --force stable >/dev/null 2>&1 || true
    git -C "$VOLUME_SDK" pull --ff-only >/dev/null 2>&1 || true
  fi

  # Dart's SDK and the engine artifacts come down on the first run of a new version.
  write_state updating "Fetching the matching Dart SDK…"
  "$VOLUME_SDK/bin/flutter" --version >/dev/null 2>&1 || true
  "$VOLUME_SDK/bin/flutter" precache --web >/dev/null 2>&1 || true

  write_state ready "Flutter $(version_of "$VOLUME_SDK") is installed."
  status
}

restore() {
  if [ ! -d "$VOLUME_SDK/.git" ]; then
    write_state failed "There is no previous SDK to return to."
    return 1
  fi

  previous=$(cat "$PREVIOUS" 2>/dev/null || true)
  if [ -z "$previous" ]; then
    write_state failed "There is no previous SDK to return to."
    return 1
  fi

  : > "$LOCK"
  trap 'rm -f "$LOCK"' EXIT INT TERM

  write_state updating "Returning to the previous SDK…"
  git -C "$VOLUME_SDK" checkout --force "$previous" >/dev/null 2>&1
  "$VOLUME_SDK/bin/flutter" --version >/dev/null 2>&1 || true

  write_state ready "Flutter $(version_of "$VOLUME_SDK") is installed."
  status
}

case "${1:-status}" in
  status) status ;;
  update) shift; update "${1:-}" ;;
  restore) restore ;;
  *)
    echo "usage: flutter-sdk {status|update [tag]|restore}" >&2
    exit 2
    ;;
esac
