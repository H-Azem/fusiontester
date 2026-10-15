#!/bin/sh
# Stops the Android container while a build runs and starts it again for the test.
#
# The pipeline needs ~2GB less memory during a build than it does during the test,
# and the device sits idle through the build holding 1.5-2.5GB. The API container is
# deliberately given no access to the Docker socket — it builds code from whatever
# repository a run points at — so it writes a flag file and this host-side watcher is
# what actually touches the container.
set -eu

CONTAINER="${FUSION_DEVICE_CONTAINER:-redroid-tester}"
CONTROL="${FUSION_CONTROL_DIR:-/srv/fusion-control}"

mkdir -p "$CONTROL"

log() {
  echo "$(date -Is) $*" >> "$CONTROL/device.log"
}

while true; do
  if [ -f "$CONTROL/pause" ]; then
    rm -f "$CONTROL/pause"
    if docker stop "$CONTAINER" >/dev/null 2>&1; then
      log "paused $CONTAINER for a build"
    else
      log "could not pause $CONTAINER"
    fi
  fi

  if [ -f "$CONTROL/resume" ]; then
    rm -f "$CONTROL/resume"

    # The kernel side is what a reboot takes away, and without it the container
    # exits the moment it starts, which looks exactly like a device that never
    # came back.
    /usr/local/bin/fusion-binderfs.sh >/dev/null 2>&1 || true

    if docker start "$CONTAINER" >/dev/null 2>&1; then
      sleep 3
      if docker ps --format "{{.Names}}" | grep -qx "$CONTAINER"; then
        log "resumed $CONTAINER for a test"
      else
        log "resumed $CONTAINER but it exited at once - check binderfs and its logs"
      fi
    else
      log "could not resume $CONTAINER"
    fi
  fi

  sleep 2
done
