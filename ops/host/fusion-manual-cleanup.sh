#!/bin/sh
# Removes the apps the manual-test page installed, an hour after the last visit.
#
# Only packages this page installed are touched, so a test that happens to be running
# keeps its own app. The device being down is not a failure: the check simply waits.
set -eu

CONTROL_DIR="${FUSION_CONTROL_DIR:-/srv/fusion-control}"
ACTIVITY="$CONTROL_DIR/manual-test-activity"
INSTALLED="$CONTROL_DIR/manual-test-installed"
IDLE_SECONDS="${FUSION_MANUAL_IDLE_SECONDS:-3600}"

DEVICE="${FUSION_DEVICE:-172.17.0.1:5555}"
ADB="${FUSION_ADB:-/root/android-sdk/platform-tools/adb}"

[ -s "$INSTALLED" ] || exit 0
[ -f "$ACTIVITY" ] || exit 0

last="$(cat "$ACTIVITY" 2>/dev/null || echo 0)"
now="$(date +%s)"

case "$last" in
    '' | *[!0-9]*) exit 0 ;;
esac

[ "$last" -gt 0 ] || exit 0
[ "$((now - last))" -ge "$IDLE_SECONDS" ] || exit 0

"$ADB" connect "$DEVICE" >/dev/null 2>&1 || true

if ! "$ADB" -s "$DEVICE" shell true >/dev/null 2>&1; then
    logger -t fusion-manual-cleanup "device $DEVICE is not answering; will try again"
    exit 0
fi

removed=0

while IFS= read -r package; do
    [ -n "$package" ] || continue

    if "$ADB" -s "$DEVICE" uninstall "$package" >/dev/null 2>&1; then
        removed=$((removed + 1))
        logger -t fusion-manual-cleanup "removed $package"
    else
        logger -t fusion-manual-cleanup "could not remove $package"
    fi
done < "$INSTALLED"

: > "$INSTALLED"
rm -f "$ACTIVITY"

logger -t fusion-manual-cleanup "idle for $((now - last))s — removed $removed app(s)"
