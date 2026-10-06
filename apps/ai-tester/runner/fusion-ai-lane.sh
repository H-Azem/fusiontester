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
AUTH_SOURCE="${FUSION_CLI_AUTH:-/home/dev/.commandcode/auth.json}"
IMAGE="${FUSION_AI_IMAGE:-tester-ai-tester}"
NETWORK="${FUSION_AI_NETWORK:-bridge}"
CONTAINER_UID="${FUSION_AI_UID:-1001}"
RUN_TIMEOUT="${FUSION_AI_TIMEOUT:-3600}"

log() { printf '%s %s\n' "$(date -Is)" "$*" >>"$LOG"; }

mkdir -p "$REQUESTS"

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

    # The CLI refuses every run until it is authenticated — BYOK included — so the
    # operator's login is handed in for the duration of the run only. The copy is
    # owned by the container's non-root user, read-only inside, and removed after.
    auth_mount=""
    if [ -f "$AUTH_SOURCE" ]; then
      cp "$AUTH_SOURCE" "$request/seed-auth.json"
      chown "$CONTAINER_UID:$CONTAINER_UID" "$request/seed-auth.json" 2>/dev/null
      chmod 600 "$request/seed-auth.json"
      auth_mount="$request/seed-auth.json:/seed-auth.json:ro"
    else
      log "$run: no CLI login at $AUTH_SOURCE; the run will fail as unauthenticated"
    fi

    if [ -n "$auth_mount" ]; then
      timeout "$RUN_TIMEOUT" docker run --rm \
        --name "ai-tester-$run" \
        --network "$NETWORK" \
        --cap-drop ALL \
        --security-opt no-new-privileges \
        --env-file "$request/env" \
        -v "$auth_mount" \
        -v "$work":/work \
        --entrypoint sh \
        "$IMAGE" -c '
          mkdir -p "$HOME/.commandcode"
          if [ -f /seed-auth.json ]; then
            cp /seed-auth.json "$HOME/.commandcode/auth.json"
            chmod 600 "$HOME/.commandcode/auth.json"
          fi
          exec sh /usr/local/bin/ai-tester-entrypoint.sh
        ' >>"$request/runner.log" 2>&1
    else
      timeout "$RUN_TIMEOUT" docker run --rm \
        --name "ai-tester-$run" \
        --network "$NETWORK" \
        --cap-drop ALL \
        --security-opt no-new-privileges \
        --env-file "$request/env" \
        -v "$work":/work \
        "$IMAGE" >>"$request/runner.log" 2>&1
    fi

    code=$?
    rm -f "$request/seed-auth.json"
    printf '%s\n' "$code" >"$request/done"
    log "$run: finished with exit $code"
  done

  sleep 2
done
