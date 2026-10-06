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

# --local-only: never contact the Command Code backend. dont-ask is the fail-closed
# mode: the settings allowlist (Shell(adb:*) plus reads/writes under /work) is all
# the agent may run, and nothing else. AGENTS.md keeps its commands plain for the
# same reason — no variables, no `;`/`&&`, no redirects or pipes.
exec cmd -p "$MISSION" \
  -m "fusion/${FUSION_AI_MODEL}" \
  --output-format json \
  --max-turns "$MAX_TURNS" \
  --permission-mode dont-ask \
  --local-only \
  --no-session \
  --no-auto-update \
  --trust \
  --skip-onboarding \
  > /work/agent.ndjson 2> /work/agent.err
