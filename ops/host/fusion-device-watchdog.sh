#!/bin/sh
# Keeps the redroid device reachable, so the emulator is not left saying Disconnected.
#
# Three ways it goes wrong, all seen in practice: the container is stopped (a build put
# it to sleep and nothing woke it), it is up but never finishes booting, or it is fine
# but the host's adb has forgotten it — which is what the stream reads.
set -eu

CONTAINER="${FUSION_DEVICE_CONTAINER:-redroid-tester}"
DEVICE="${FUSION_DEVICE:-172.17.0.1:5555}"
ADB="${FUSION_ADB:-/root/android-sdk/platform-tools/adb}"
BOOT_GRACE_SECONDS="${FUSION_BOOT_GRACE_SECONDS:-300}"

log() { logger -t fusion-device-watchdog "$1"; }

recover() {
    systemctl restart ws-scrcpy >/dev/null 2>&1 || true
}

running="$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null || echo '')"

if [ "$running" = "" ]; then
    log "container $CONTAINER does not exist"
    exit 0
fi

if [ "$running" != "true" ]; then
    log "container $CONTAINER was down; starting it"
    docker start "$CONTAINER" >/dev/null 2>&1 || true
    recover
    exit 0
fi

# Reach it before judging it: with no device in adb, "not booted" is unanswerable, and
# reading it as a wedged container restarts a machine that was only unreachable.
reachable() {
    "$ADB" devices | awk 'NR > 1 && $2 == "device" { found = 1 } END { exit !found }'
}

if ! reachable; then
    if "$ADB" connect "$DEVICE" >/dev/null 2>&1 && reachable; then
        log "reconnected $DEVICE to the host adb"
        recover
    else
        log "container is up but $DEVICE is not reachable yet"
        exit 0
    fi
fi

# Up, reachable, and still not booting after the grace period: wedged, not slow.
started="$(docker inspect -f '{{.State.StartedAt}}' "$CONTAINER" 2>/dev/null || echo '')"
started_epoch="$(date -d "$started" +%s 2>/dev/null || echo 0)"
now="$(date +%s)"

if [ "$started_epoch" -gt 0 ] && [ "$((now - started_epoch))" -gt "$BOOT_GRACE_SECONDS" ]; then
    boot="$("$ADB" -s "$DEVICE" shell getprop sys.boot_completed 2>/dev/null | tr -d '\r' || echo '')"

    if [ "$boot" != "1" ]; then
        log "up for $((now - started_epoch))s without booting; restarting"
        docker restart "$CONTAINER" >/dev/null 2>&1 || true
        recover
    fi
fi
