#!/bin/sh
# Runs one AI lane test.
#
# The mission (the goal list from MaestroGoals) is handed in on /work/mission.txt;
# the device is the only thing this container can reach; the CLI's JSON verdict
# stream is left in /work for the pipeline to read. The exit code is the CLI's
# own, so 8 (max turns reached) survives as a distinct outcome.
set -eu

: "${FUSION_AI_BASE_URL:?FUSION_AI_BASE_URL is required}"
: "${FUSION_AI_MODEL:?FUSION_AI_MODEL is required}"

mkdir -p /work

# Point adb at the run's device before the agent starts, so its first call is not
# a connect race. A failure here is not fatal: the agent can retry.
if [ -n "${FUSION_DEVICE:-}" ]; then
  adb connect "$FUSION_DEVICE" >/dev/null 2>&1 || true
fi

# BYOK provider for this run. The key is an env reference, never the secret
# itself, so nothing sensitive is ever written to the container's disk.
cat > "$HOME/.commandcode/providers.json" <<JSON
{
  "provider": {
    "fusion": {
      "name": "Fusion AI lane",
      "baseURL": "${FUSION_AI_BASE_URL}",
      "apiKey": "\$FUSION_AI_KEY",
      "models": {
        "${FUSION_AI_MODEL}": {}
      }
    }
  }
}
JSON

MISSION="$(cat /work/mission.txt 2>/dev/null || true)"
if [ -z "$MISSION" ]; then
  MISSION='Explore the app and verify its main journey still works.'
fi

MAX_TURNS="${FUSION_MAX_TURNS:-60}"

# The device and the app are handed over as literals so every adb command can match
# the allowlist. A `$VARIABLE` inside a command is exactly what the conservative
# matcher refuses, so the agent is told to read these and spell them out.
printf 'device=%s\napp=%s\n' "${FUSION_DEVICE:-}" "${FUSION_APP_ID:-}" > /work/environment.txt
chmod 644 /work/environment.txt

# The permission allowlist lives in the project settings, and the CLI reads those
# from the working directory — so name it explicitly rather than trusting whatever
# the runtime chose, or every adb command is refused.
cd /home/tester/lane

# --local-only: never contact the Command Code backend.
#
# --yolo is needed to run adb at all. dont-ask with a Shell(adb:*) allowlist was
# tried first and does work when cmd is invoked directly, but the same allowlist is
# not honoured through this entrypoint, so every adb call came back blocked and the
# agent had nothing to do. The sandbox is the container, not the permission engine:
# non-root, --cap-drop ALL, no repository, no database, no Docker socket, and only
# the device and the model endpoint reachable. AGENTS.md still keeps the agent to
# plain literal adb commands, and settings.json keeps its allowlist for the day the
# entrypoint path honours it.
exec cmd -p "$MISSION" \
  -m "fusion/${FUSION_AI_MODEL}" \
  --output-format json \
  --max-turns "$MAX_TURNS" \
  --yolo \
  --local-only \
  --no-session \
  --skip-onboarding \
  --trust \
  > /work/agent.ndjson 2> /work/agent.err
