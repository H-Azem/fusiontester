#!/bin/sh
# Host-side runner for the AI lane.
#
# The API container is deliberately given no Docker socket, so it cannot start the
# ai-tester container itself. Instead it drops a request under $CONTROL/ai/<runId>/
# and this watcher — the same pattern as fusion-device-watch — picks it up, runs the
# container exactly once, and leaves the result beside the request.
#
# A request directory looks like:
#   <runId>/env           the run's environment (base URL, model, key, max turns)
#   <runId>/work/         mounted at /work in the container; holds mission.txt and
#                         receives agent.ndjson, agent.err and any screenshots
#   <runId>/request.json  written last by the API; its presence means "run me"
#
# Once claimed, the runner writes <runId>/done containing the container's exit code.
set -u

CONTROL="${FUSION_CONTROL_DIR:-/srv/fusion-control}"
REQUESTS="$CONTROL/ai"
LOG="$CONTROL/ai-lane.log"
IMAGE="${FUSION_AI_IMAGE:-tester-ai-tester}"
NETWORK="${FUSION_AI_NETWORK:-bridge}"
RUN_TIMEOUT="${FUSION_AI_TIMEOUT:-3600}"

log() { printf '%s %s\n' "$(date -Is)" "$*" >>"$LOG"; }

mkdir -p "$REQUESTS"

# Runs the container, and stops it early if the API drops a `cancel` marker into the
# request — the only way to interrupt a lane that is already driving the device.
run_lane() {
  timeout "$RUN_TIMEOUT" docker run --rm \
    --name "ai-tester-$run" \
    --network "$NETWORK" \
    --cap-drop ALL \
    --security-opt no-new-privileges \
    --env-file "$request/env" \
    "$@" >>"$request/runner.log" 2>&1 &

  lane_pid=$!

  while kill -0 "$lane_pid" 2>/dev/null; do
    if [ -f "$request/cancel" ]; then
      docker kill "ai-tester-$run" >/dev/null 2>&1
      log "$run: cancelled on request"
      break
    fi

    sleep 2
  done

  wait "$lane_pid"
  return $?
}

while true; do
  for request in "$REQUESTS"/*; do
    [ -d "$request" ] || continue
    # request.json is written last, so its absence means "not ready yet".
    [ -f "$request/request.json" ] || continue

    run="$(basename "$request")"
    work="$request/work"

    if [ ! -d "$work" ]; then
      log "$run: no work directory; skipping"
      mv "$request/request.json" "$request/skipped" 2>/dev/null
      continue
    fi

    # Claim it before doing anything, so a crash mid-run cannot loop forever.
    mv "$request/request.json" "$request/taken" 2>/dev/null || continue
    log "$run: starting"

    # The lane's model credential travels in the request env file, so there is
    # nothing to seed into the container: it runs straight from the image.
    run_lane -v "$work":/work "$IMAGE"

    code=$?
    printf '%s\n' "$code" >"$request/done"
    log "$run: finished with exit $code"
  done

  sleep 2
done
