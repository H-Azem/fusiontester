# AI lane — handoff and plan

Written at the end of a long session because its context filled up. Everything durable is
in git; this file exists so the next session does not have to rediscover it.

## What to say to the next session

> Read `AI-LANE-PLAN.md` and start phase 0 and 1 of the AI lane.

## Where things stand

The platform runs at **https://fusion.azemdev.com** from `/home/dev/Documents/commandcode/untitled-project/fusiontester`
(docker compose project `tester`: `tester-api-1`, `tester-web-1`, plus the device container
`redroid-tester`). The API is Laravel 13 on PHP 8.3 with SQLite; the dashboard is Next.js 16 on
React 19 with hand-written CSS. The device is redroid (Android 13, x86_64), driven with adb at
`172.17.0.1:5555`; the pipeline builds Flutter apps and runs Maestro flows on it.

Recent work, all committed and live:

- `smoke` runs before any other flow so the app is signed in, with `full_app` left to sign in
  by itself (`MaestroWorkspace::withSmokeFirst`).
- Tests are listed as the **leaves** of `.maestro/flows` (`orders/add_order`), the way the POS
  repo's own `scripts/run_maestro.sh` finds them; `shared/` and `full_app/` are reserved.
- The device is given **Null Keyboard** (`com.nilac.nullkeyboard`) automatically: the APK ships
  in `apps/api-laravel/resources/ime/null-keyboard/` and `prepareDevice()` installs and selects
  it. A real IME stays enabled on purpose — Android restores its default when none is enabled.
- Maestro's cloud advertisement is stripped from stored output; a run that has live frames hides
  the Maestro failure screenshot.
- Telegram reports: optional **topic id**, plus a master **Send reports** switch.
- Production builds are commented out in the run sheet (and the payload only ever asks for
  development).
- Settings sit behind a second password; username is `fusion`; the run sheet groups nested tests
  under their folder, open by default.

Open, unrelated to the AI lane:

- The GitLab token in the database cannot call the REST API (401) — a PAT with `api` scope is
  still needed. Cloning works.
- On a run of `pos`, `customers` fails at `home_screen`: the app relaunches on the staff PIN
  screen and the PIN is not entered. Two files would settle it: `shared/launch_warm.yaml` and
  `shared/enter_correct_staff_pin.yaml`.
- A stray `docker compose … up -d --build` from an old session may still be running on the host;
  kill it by pid (never with a text pattern that matches your own shell).

## The AI lane

The goal: a lane that behaves like a normal user. It is *inspired* by the Maestro flows — they
say which parts deserve testing — but it drives the app itself and ends with a report, with
screenshots where something failed.

The project already has an AI lane: `ai-screenshot` artifacts exist, `Running AI checks…` appears
in run steps, and Settings holds `openaiBaseUrl`/`openaiModel`/`openaiToken` plus
`jevBaseUrl`/`jevToken` and `maxSteps`. **Phase 0 is to read it before writing anything.**

### Shape

```
run with runKinds including ai
  -> the pipeline turns the Maestro flows into a plain-text goal list (no model, free)
  -> a host-side runner (user `dev`) starts the `ai-tester` container for that run
  -> the container runs:  cmd -p … --yolo --output-format json --max-turns N
       - it dumps the accessibility tree itself (uiautomator) and works from text:
         a compact element menu costs ~400 tokens against ~1.5-2k for a screenshot
       - it uses the Settings credentials as a BYOK provider
       - at judgement points it asks Jev (one call, several typed questions)
       - it captures a screenshot only when something fails, downscaled
  -> report.json + screenshots/ land in a shared volume
  -> the API stores them as run artifacts, like Maestro's
  -> the report is shown in the panel, and sent to Telegram when that is enabled
```

### Phases

0. **Read the existing lane** (`AiClient`, its actions, the `ai-screenshot` path) and say in one
   sentence where the new one plugs in.
1. **Settings**: the AI card gains "AI test lane" (on/off) and "share the report with Telegram".
   Reuse the existing credential fields.
2. **Goals from Maestro**: a small service that reads the flow YAML and produces goals
   (`reach home`, `open customers`, `add a customer`, `it is visible`). No model involved.
3. **`ai-tester` container**: node + the CLI + platform-tools; internal network with only the
   device; no docker socket, no repo mount, no database; non-root; `--cap-drop ALL`; tool
   allowlist of `adb` only (fail-closed); `--max-turns` bounded; key passed by env reference
   (`$VAR`), never a file in the host's home.
4. **Host runner**: a small unit that picks up a request and runs the container, the same pattern
   as `fusion-device-watch`.
5. **Report and screenshots**: goals with PASS/FAIL, the evidence text, the run's `usage` and
   cost, and the paths of any failure screenshots.
6. **Telegram**: if sending is enabled, the report goes to the configured channel and topic, with
   a link to the run.
7. **Tests and release**: unit tests for goal extraction and for reading `report.json`, then one
   real run of `customers` to see the actual cost from `usage`.

### Decisions already made

- Sandbox by **where it runs** (a container), not by which user — so nothing needs root and the
  lane can still be part of the live system.
- Keep the existing lane's plumbing (artifacts, the panel, cleanup, Telegram) and change what
  drives it.
- Determinism is not the goal for this lane; discovery and the quality of the report are.
- Safety: the device is the only thing it can damage, and the app is removed after every run
  anyway; production is out of the picture.
