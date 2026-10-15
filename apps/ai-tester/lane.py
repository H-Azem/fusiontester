#!/usr/bin/env python3
"""One AI lane run.

The pipeline hands a mission in on /work/mission.txt and reads a verdict back out
of /work. Everything between is this file's job: point the agent at the run's
model endpoint and device, drive the app, then write the three files the pipeline
already knows how to read:

    /work/report.json     summary, goals, screenshots, usage
    /work/agent.ndjson    one "result" frame (finalText + usage)
    /work/agent.err       the run's notes, for a failure

The run is bounded by tokens, not by turns: a journey that keeps making progress
is allowed to finish, and the ceiling only stops it from costing a fortune.

Exit codes: 0 finished, 8 ran out of budget, 1 anything else.
"""

import asyncio
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import traceback
import uuid
from pathlib import Path

WORK = Path("/work")
ARTEMIS = Path("/opt/artemis")
OVERRIDES = Path("/opt/lane/artemis.overrides.jsonc")
TRACES = Path("/tmp/lane-traces")

# A copy of the run's own stream. The agent states its verdict through its status
# call, which only ever goes to the stream — so the stream is where the verdict is
# read from when no note was written.
RUN_LOG = Path("/tmp/lane-run.log")

# How often the running token total is checked against the budget.
POLL_SECONDS = 5

REPORT_SCHEMA = (
    '{"summary": "one sentence", '
    '"goals": [{"text": "the feature", "status": "pass" or "fail", '
    '"evidence": "what on screen proves it"}]}'
)

# Stated before the mission and repeated after it. The note is the only thing the
# pipeline can read back, so it must not be left in a status message instead.
REPORT_INSTRUCTION = (
    "HOW YOU WILL BE READ BACK\n"
    "When the work is done, call the save_note tool once:\n"
    '    save_note(key="report", content=<one JSON object>)\n'
    "The content must be exactly this shape, with one goals entry per feature "
    "named in the mission:\n"
    "    " + REPORT_SCHEMA + "\n"
    "Put nothing else in that note — no prose, no markdown fence. Wherever else you "
    "describe the outcome (a status message, your closing summary), it is not read: "
    "the note is.\n"
    "--- mission ---\n"
)

REPORT_REMINDER = (
    "\n\nBefore you stop: save_note(key=\"report\", content=" + REPORT_SCHEMA + ")\n"
    "A verdict you write anywhere else is not read back."
)


def note(message: str) -> None:
    print(message, file=sys.stderr, flush=True)


class Tee:
    """Writes to the real stream and to a file, so the run's words survive it."""

    def __init__(self, stream, handle):
        self._stream = stream
        self._handle = handle

    def write(self, data):
        self._stream.write(data)
        self._handle.write(data)
        return len(data)

    def flush(self):
        self._stream.flush()
        self._handle.flush()

    def isatty(self):
        return False


def required(name: str) -> str:
    value = os.environ.get(name, "")

    if value == "":
        note(f"{name} is required")
        sys.exit(1)

    return value


def configure_endpoint() -> None:
    """Everything the agent reads at import time must be in place first."""

    base = required("FUSION_AI_BASE_URL")
    model = required("FUSION_AI_MODEL")

    device = os.environ.get("FUSION_DEVICE", "")

    if device != "":
        # The adapter talks to a local adb *server*, which in turn knows the
        # device. Pointing it straight at the device's adbd port makes it wait
        # for a server handshake that never comes, so: start the server here and
        # introduce the device to it, exactly as the old entrypoint did.
        try:
            subprocess.run(["adb", "start-server"], timeout=30, check=False)
            subprocess.run(["adb", "connect", device], timeout=30, check=False)
        except (OSError, subprocess.SubprocessError) as exc:
            note(f"could not introduce adb to {device}: {exc}")

        os.environ.setdefault("ADB_HOST", "127.0.0.1")
        os.environ.setdefault("ADB_PORT", "5037")
        os.environ["ADB_DEVICE_SERIAL"] = device

    # The lane's own endpoint, by reference: the key stays in the environment.
    os.environ["OPENAI_BASE_URL"] = base
    os.environ["OPENAI_API_KEY"] = os.environ.get("FUSION_AI_KEY", "")
    os.environ["ARTEMIS_TRACES_DIR"] = str(TRACES)
    os.environ["ARTEMIS_DEFAULT_PROFILE"] = "flash"
    # The accessibility helper does not disturb Maestro the way the uiautomator2
    # path does, and a run may also drive the same device with Maestro flows.
    os.environ["ARTEMIS_HIERARCHY_BACKEND"] = "auto"
    os.environ["ARTEMIS_KEEP_DEVICE_AWAKE"] = "false"
    # A node whose reply will not parse makes the agent pause for a resume signal;
    # the default waits a quarter of an hour. Fail the run instead of holding the
    # device and the budget hostage.
    os.environ["LLM_PAUSE_TIMEOUT_SECONDS"] = os.environ.get("FUSION_LLM_PAUSE_SECONDS", "120")

    write_config(model)


def deep_merge(base: dict, over: dict) -> dict:
    merged = dict(base)

    for key, value in over.items():
        if isinstance(value, dict) and isinstance(merged.get(key), dict):
            merged[key] = deep_merge(merged[key], value)
        else:
            merged[key] = value

    return merged


def substitute(node, model: str):
    if isinstance(node, dict):
        return {key: substitute(value, model) for key, value in node.items()}

    if isinstance(node, list):
        return [substitute(item, model) for item in node]

    if node == "@@MODEL@@":
        return model

    return node


def write_config(model: str) -> None:
    """The agent's shipped config, with this run's deltas merged in."""

    from third_party.mobile_use.utils.file import load_jsonc

    bundled = ARTEMIS / "artemis" / "resources" / "config" / "artemis.jsonc"

    with bundled.open(encoding="utf-8") as handle:
        config = load_jsonc(handle)

    with OVERRIDES.open(encoding="utf-8") as handle:
        overrides = load_jsonc(handle)

    config = deep_merge(config, overrides)
    config = substitute(config, model)

    target = ARTEMIS / "config" / "artemis.jsonc"
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(config, indent=2), encoding="utf-8")


class BudgetExceeded(Exception):
    """The run passed its token budget and was stopped."""

    def __init__(self, session_id: str):
        super().__init__("token budget exceeded")
        self.session_id = session_id


def budget() -> int:
    try:
        return int(os.environ.get("FUSION_MAX_TOKENS", "0") or 0)
    except ValueError:
        return 0


async def _run(mission: str, session_id: str, ceiling: int) -> None:
    from artemis.interfaces.cli.commands.run import execute_task

    task = asyncio.ensure_future(
        execute_task(
            goal=REPORT_INSTRUCTION + mission + REPORT_REMINDER,
            device_serial=os.environ.get("FUSION_DEVICE") or None,
            session_id=session_id,
            traces_output_path_str=str(TRACES),
            # No structured-output request: it makes the agent answer from the
            # prompt instead of going to look, and the verdict comes back as the
            # note the goal asks for anyway.
            profile="flash",
            video_recording_tools_enabled=False,
        )
    )

    if ceiling <= 0:
        await task
        return

    # Watch the session's running totals and stop the moment the budget is gone.
    # Cancelling is graceful — the run finalises its trace and keeps what it had
    # reached, which beats being killed from outside with nothing to show.
    while True:
        done, _ = await asyncio.wait({task}, timeout=POLL_SECONDS)

        if task in done:
            break

        if spent(session_id) > ceiling:
            task.cancel()

            try:
                await task
            except BaseException:  # noqa: BLE001 - cancellation and failure alike
                pass

            raise BudgetExceeded(session_id)

    await task


def run(mission: str) -> str:
    session_id = str(uuid.uuid4())
    asyncio.run(_run(mission, session_id, budget()))

    return session_id


def spent(session_id: str) -> int:
    totals = usage(session_id)

    return int(totals.get("inputTokens", 0)) + int(totals.get("outputTokens", 0))


def newest(pattern: str):
    matches = [path for path in TRACES.glob(pattern) if path.is_file()]

    return max(matches, key=lambda path: path.stat().st_mtime) if matches else None


def session_status(session_id: str) -> str:
    database = newest("**/data_engine.db") or (TRACES / "data_engine.db")

    if not Path(database).is_file():
        return ""

    try:
        with sqlite3.connect(str(database)) as connection:
            row = connection.execute(
                "select status from sessions where session_id = ?", (session_id,)
            ).fetchone()
    except sqlite3.Error:
        return ""

    return str(row[0]) if row else ""


def usage(session_id: str) -> dict:
    database = newest("**/data_engine.db") or (TRACES / "data_engine.db")

    if not Path(database).is_file():
        return {}

    try:
        with sqlite3.connect(str(database)) as connection:
            rows = [
                payload
                for (payload,) in connection.execute(
                    "select payload from traces where name='llm_usage' and session_id=?",
                    (session_id,),
                )
                if payload and payload.strip().startswith("{")
            ]
    except sqlite3.Error:
        return {}

    if not rows:
        return {}

    try:
        last = json.loads(rows[-1])
    except ValueError:
        return {}

    return {
        "inputTokens": int(last.get("session_prompt_tokens") or 0),
        "outputTokens": int(last.get("session_completion_tokens") or 0),
    }


def report_note() -> dict | None:
    note_file = newest("**/notes/report.md")

    if note_file is None:
        return None

    return _as_report(note_file.read_text(encoding="utf-8", errors="replace"))


def report_from_log() -> dict | None:
    """The verdict the agent stated in its status call, read back out of the stream."""

    if not RUN_LOG.is_file():
        return None

    text = RUN_LOG.read_text(encoding="utf-8", errors="replace")
    marker = text.rfind('{"summary"')

    while marker != -1:
        end = _balanced_end(text, marker)

        if end is not None:
            report = _as_report(text[marker:end])

            if report is not None and isinstance(report.get("goals"), list):
                return report

        # The same shape can appear in the prompt or an earlier attempt.
        marker = text.rfind('{"summary"', 0, marker)

    return None


def _balanced_end(text: str, start: int) -> int | None:
    """Index just past the object that opens at `start`, or None if it never closes."""

    depth = 0
    in_string = False
    escaped = False

    for index in range(start, len(text)):
        char = text[index]

        if in_string:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == '"':
                in_string = False
            continue

        if char == '"':
            in_string = True
        elif char == "{":
            depth += 1
        elif char == "}":
            depth -= 1

            if depth == 0:
                return index + 1

    return None


def _as_report(text: str) -> dict | None:
    for candidate in (text, _brace_slice(text)):
        if not candidate:
            continue

        try:
            parsed = json.loads(candidate)
        except ValueError:
            continue

        if isinstance(parsed, dict):
            return parsed

    return None


def _brace_slice(text: str) -> str:
    start = text.find("{")
    end = text.rfind("}")

    return text[start : end + 1] if start != -1 and end > start else ""


def summary_fallback() -> str:
    note_file = newest("**/notes/output.md")

    if note_file is None:
        return ""

    return note_file.read_text(encoding="utf-8", errors="replace").strip()


def copy_screenshots(limit: int = 3) -> list[str]:
    frames = sorted(
        (
            path
            for path in TRACES.glob("**/images/*")
            if path.suffix.lower() in (".png", ".jpg", ".jpeg")
        ),
        key=lambda path: path.stat().st_mtime,
        reverse=True,
    )[:limit]

    names = []
    WORK.joinpath("screenshots").mkdir(parents=True, exist_ok=True)

    for index, frame in enumerate(reversed(frames), start=1):
        name = f"{index}{frame.suffix.lower()}"
        shutil.copyfile(frame, WORK / "screenshots" / name)
        names.append(name)

    return names


def main() -> int:
    WORK.mkdir(parents=True, exist_ok=True)
    TRACES.mkdir(parents=True, exist_ok=True)

    configure_endpoint()

    mission = "/work/mission.txt"
    goal = Path(mission).read_text(encoding="utf-8").strip() if Path(mission).is_file() else ""
    goal = goal or "Explore the app and verify its main journey still works."

    session_id = ""
    failure = ""
    exhausted = False

    with RUN_LOG.open("w", encoding="utf-8") as handle:
        real_out, real_err = sys.stdout, sys.stderr
        sys.stdout, sys.stderr = Tee(real_out, handle), Tee(real_err, handle)

        try:
            session_id = run(goal)
        except BudgetExceeded as exc:
            session_id = exc.session_id
            exhausted = True
        except Exception:  # noqa: BLE001 - the lane must always leave a verdict
            failure = traceback.format_exc()
        finally:
            sys.stdout, sys.stderr = real_out, real_err

    status = session_status(session_id) if session_id else ""
    tokens = usage(session_id) if session_id else {}
    report = report_note() or report_from_log() or {}
    passed = status == "completed"

    summary = str(report.get("summary") or "").strip() or summary_fallback() or failure.strip()
    goals = report.get("goals") if isinstance(report.get("goals"), list) else []
    shots = [] if passed else copy_screenshots()

    payload = {
        "summary": summary or ("The lane did not finish." if not passed else "AI checks passed."),
        "goals": goals,
        "screenshots": shots,
    }

    if tokens:
        payload["usage"] = tokens

    (WORK / "report.json").write_text(
        json.dumps(payload, indent=4, ensure_ascii=False), encoding="utf-8"
    )

    frame = {
        "type": "result",
        "subtype": _subtype(status, failure),
        "usage": tokens,
        "finalText": payload["summary"],
    }
    (WORK / "agent.ndjson").write_text(json.dumps(frame) + "\n", encoding="utf-8")

    notes = failure or "\n".join(
        line for line in (summary, f"session status: {status}") if line
    )
    (WORK / "agent.err").write_text(notes.strip() + "\n", encoding="utf-8")

    if passed and not failures(goals):
        return 0

    if exhausted or _ran_out_of_steps(notes, goals):
        return 8

    return 1


def failures(goals: list) -> bool:
    return any(
        str(goal.get("status", "")).lower() == "fail"
        for goal in goals
        if isinstance(goal, dict)
    )


def _subtype(status: str, failure: str) -> str:
    if failure:
        return "error"

    return "success" if status == "completed" else (status or "unknown")


def _ran_out_of_steps(notes: str, goals: list) -> bool:
    lowered = notes.lower()

    return any(
        marker in lowered
        for marker in ("maximum number of turns", "max turns", "turn limit", "out of turns")
    )


if __name__ == "__main__":
    raise SystemExit(main())
