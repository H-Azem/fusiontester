"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import { Bird, BIRD_FOR_STATUS, type BirdState } from "./bird";
import { Icon } from "./icons";
import { StatusChip, relativeTime, shortDuration, stepGlyph, statusTone } from "./status";
import { useRun } from "./use-runs";

const LIVE_REFRESH_MS = 2500;

const VERDICT: Record<string, { title: string; sub: string }> = {
  passed: { title: "Test passed", sub: "Every selected flow reached the end." },
  failed: { title: "Test failed", sub: "A step below reports what it saw." },
  running: { title: "Running", sub: "The pipeline is working through the steps." },
  queued: { title: "Queued", sub: "A worker will pick this up shortly." },
};

/** `orders/mark_ready_complete` -> `Orders · Mark ready complete`. */
function testLabel(name: string): string {
  return name
    .split("/")
    .filter(Boolean)
    .map((part) => {
      const spaced = part.replace(/_/g, " ");
      return spaced.charAt(0).toUpperCase() + spaced.slice(1);
    })
    .join(" · ");
}

const RUN_KIND_LABELS: Record<string, string> = { ai: "AI", maestro: "Maestro", manual: "Manual APK" };

type StepResult = { passed: boolean; name: string; meta?: string };

/** `[Passed] full_test (1m 51s)` — Maestro names each flow it ran. */
function parseFlowResults(output: string): StepResult[] {
  const results: StepResult[] = [];

  for (const match of output.matchAll(/^\[(Passed|Failed|Skipped)\]\s+(.+?)(?:\s+\(([^)]*)\))?\s*$/gm)) {
    results.push({ passed: match[1] === "Passed", name: match[2].trim(), meta: match[3]?.trim() });
  }

  return results;
}

/** `✓ open customers — the list is on screen` — the lane's own verdict per goal. */
function parseGoalResults(output: string): StepResult[] {
  const results: StepResult[] = [];

  for (const match of output.matchAll(/^([✓✗])\s+(.+?)(?:\s+—\s+(.*))?\s*$/gm)) {
    results.push({ passed: match[1] === "✓", name: match[2].trim(), meta: match[3]?.trim() });
  }

  return results;
}

/**
 * The steps that run tests have their own shape — flows and goals, each with a
 * verdict — so the raw text is turned into the list a person actually reads.
 */
function StepResults({ step }: { step: { key: string; output: string | null } }) {
  const output = step.output ?? "";
  const results = step.key === "maestro" ? parseFlowResults(output) : step.key === "ai" ? parseGoalResults(output) : [];

  if (results.length === 0) return null;

  return (
    <ul className="stack" style={{ listStyle: "none", padding: 0, margin: "10px 0 0", gap: 6 }}>
      {results.map((result, index) => (
        <li key={index} className="row" style={{ alignItems: "flex-start", gap: 10 }}>
          <StatusChip status={result.passed ? "passed" : "failed"} label={result.passed ? "pass" : "fail"} />
          <span className="stack" style={{ gap: 2, flex: 1 }}>
            <span className="md-title-sm">{result.name}</span>
            {result.meta && <span className="md-body-sm muted">{result.meta}</span>}
          </span>
        </li>
      ))}
    </ul>
  );
}

/**
 * The device screen while a run is in flight. The frame is overwritten in place on
 * the server, so the query string is what makes the browser ask for the new one.
 */
function LiveView({ runId, running, hasFrame }: { runId: string; running: boolean; hasFrame: boolean }) {
  const [tick, setTick] = useState(0);
  const [missing, setMissing] = useState(!hasFrame);

  useEffect(() => {
    if (!running) return;
    const timer = setInterval(() => setTick((value) => value + 1), LIVE_REFRESH_MS);
    return () => clearInterval(timer);
  }, [running]);

  // The first frame arrives well after the page does, and the image has to stay
  // mounted for onLoad to ever fire: hiding it behind the placeholder is what made
  // this sit on "Waiting for the first frame" forever.
  useEffect(() => {
    if (hasFrame) setMissing(false);
  }, [hasFrame]);

  if (!running && !hasFrame) return null;

  return (
    <section className="panel">
      <div className="panel-head">
        <h2>Live device</h2>
        <span className="row">
          {running && (
            <span className="status running">
              <span className="dot" aria-hidden="true" />
              streaming
            </span>
          )}
        </span>
      </div>

      <div className="frame-box">
        {missing && <div className="live-frame pending">Waiting for the first frame…</div>}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          className={missing ? "live-frame is-hidden" : "live-frame"}
          src={`/api/runs/${runId}/live?t=${tick}`}
          alt="Device screen"
          onError={() => setMissing(true)}
          onLoad={() => setMissing(false)}
        />
      </div>
    </section>
  );
}

function ScreenshotPanel({
  runId,
  kind,
  title,
  note,
}: {
  runId: string;
  kind: "screenshot" | "maestro-screenshot" | "ai-screenshot";
  title: string;
  note: string;
}) {
  return (
    <section className="panel">
      <div className="panel-head">
        <h2>{title}</h2>
        <a className="md-button text small" href={`/api/runs/${runId}/${kind}`} target="_blank" rel="noreferrer">
          <Icon name="external" size={16} />
          Full size
        </a>
      </div>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <div className="frame-box">
        <img className="live-frame" src={`/api/runs/${runId}/${kind}`} alt="Screenshot" />
      </div>
      <p className="md-body-sm muted">{note}</p>
    </section>
  );
}

type AiGoal = { text?: string; status?: string; evidence?: string };

type AiReport = {
  summary?: string;
  goals?: AiGoal[];
  screenshots?: string[];
  usage?: { inputTokens?: number; outputTokens?: number };
};

/**
 * The lane's own verdict. It runs once the run is over and writes its report to
 * disk, so this reads it once rather than polling.
 */
function AiReportPanel({ runId }: { runId: string }) {
  const [report, setReport] = useState<AiReport | null>(null);

  useEffect(() => {
    let cancelled = false;

    void (async () => {
      const response = await fetch(`/api/runs/${runId}/ai-report`, { cache: "no-store" });
      if (!response.ok) return;
      const data = (await response.json()) as AiReport;
      if (!cancelled) setReport(data);
    })();

    return () => {
      cancelled = true;
    };
  }, [runId]);

  const goals = report?.goals ?? [];
  if (!report || (goals.length === 0 && !report.summary)) return null;

  const failed = goals.filter((goal) => goal.status === "fail").length;
  const usage = report.usage;
  const tokens = usage ? (usage.inputTokens ?? 0) + (usage.outputTokens ?? 0) : 0;
  const shots = report.screenshots ?? [];

  return (
    <section className="panel">
      <div className="panel-head">
        <div>
          <h2>AI report</h2>
          <p className="panel-sub">{report.summary ?? "The lane's verdict on each goal."}</p>
        </div>
        <StatusChip
          status={failed > 0 ? "failed" : "passed"}
          label={`${goals.length - failed}/${goals.length} goals`}
        />
      </div>

      <ol className="stack" style={{ listStyle: "none", padding: 0, margin: 0 }}>
        {goals.map((goal, index) => (
          <li key={index} className="card filled">
            <div className="row" style={{ justifyContent: "space-between", gap: 12 }}>
              <span className="md-title-sm">{goal.text ?? "(unnamed goal)"}</span>
              <StatusChip
                status={goal.status === "fail" ? "failed" : "passed"}
                label={goal.status === "fail" ? "fail" : "pass"}
              />
            </div>
            {goal.evidence && <p className="md-body-sm muted">{goal.evidence}</p>}
          </li>
        ))}
      </ol>

      {tokens > 0 && (
        <p className="md-body-sm muted">
          {tokens.toLocaleString()} tokens
          {usage?.inputTokens ? ` · ${usage.inputTokens.toLocaleString()} in` : ""}
          {usage?.outputTokens ? ` · ${usage.outputTokens.toLocaleString()} out` : ""}
        </p>
      )}

      {shots.length > 0 && (
        <div className="row" style={{ flexWrap: "wrap" }}>
          {shots.map((file) => (
            <a key={file} href={`/api/runs/${runId}/ai-shot/${file}`} target="_blank" rel="noreferrer">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img className="live-frame" src={`/api/runs/${runId}/ai-shot/${file}`} alt={file} />
            </a>
          ))}
        </div>
      )}
    </section>
  );
}

export function RunDetail({ id }: { id: string }) {
  const { run, error, loaded, reload } = useRun(id);
  const [cancelling, setCancelling] = useState(false);

  async function cancelRun() {
    setCancelling(true);

    try {
      await fetch(`/api/runs/${id}/cancel`, { method: "POST" });
      reload();
    } finally {
      setCancelling(false);
    }
  }

  if (error) {
    return (
      <div className="empty-state">
        <Bird state="not-found" size={120} float />
        <h3 className="md-title">That run is gone</h3>
        <p className="md-body">{error}</p>
        <Link href="/runs" className="md-button filled">
          <Icon name="runs" size={18} />
          Back to test runs
        </Link>
      </div>
    );
  }

  if (!loaded || !run) {
    return (
      <div className="stack" aria-busy="true">
        <span className="skeleton" style={{ height: 88, borderRadius: 24 }} />
        <span className="skeleton" style={{ height: 240, borderRadius: 24 }} />
      </div>
    );
  }

  const verdict = VERDICT[run.status] ?? { title: run.status, sub: "" };
  const bird: BirdState = BIRD_FOR_STATUS[run.status] ?? "unknown";
  const duration = shortDuration(run.startedAt, run.finishedAt);
  // A stage nobody selected is not part of this run — and until the pipeline reaches
  // it, it is still only "pending", so what was selected decides, not the status.
  const visibleSteps = run.steps.filter((step) => {
    if (step.status === "skipped") return false;
    if (step.key === "maestro" || step.key === "ai") return run.runKinds.includes(step.key);

    return true;
  });

  return (
    <>
      <p className="breadcrumb">
        <Link href="/runs">Test runs</Link>
        <span aria-hidden="true">/</span>
        <span className="mono">{run.id.slice(0, 8)}</span>
      </p>

      <section className="panel">
        <div className="verdict">
          <Bird state={bird} size={72} float={run.status === "running"} />
          <div className="verdict-copy">
            <span className="verdict-title">{verdict.title}</span>
            <span className="verdict-sub">
              {run.projectPath} · <span className="mono">{run.branch}</span>
              {duration ? ` · took ${duration}` : ""}
            </span>
            <span className="row" style={{ marginTop: 8 }}>
              <StatusChip status={run.status} />
              {run.platform === "android" ? (
                <span className="status queued">
                  <Icon name="device" size={14} />
                  Android device
                </span>
              ) : (
                <span className="status queued">Web</span>
              )}
              {run.live && <span className="status running">live view</span>}
            </span>
          </div>
        </div>

        <dl className="meta-grid">
          <div>
            <dt>Tests</dt>
            <dd>{run.tests.length > 0 ? run.tests.map(testLabel).join(", ") : "all flows"}</dd>
          </div>
          <div>
            <dt>Run with</dt>
            <dd>{run.runKinds.map((kind) => RUN_KIND_LABELS[kind] ?? kind).join(" + ") || "—"}</dd>
          </div>
          <div>
            <dt>Environment</dt>
            <dd>{run.environments.join(" + ")}</dd>
          </div>
          <div>
            <dt>Orientation</dt>
            <dd>{run.orientation === "vertical" ? "Vertical" : "Horizontal"}</dd>
          </div>
          <div>
            <dt>Started</dt>
            <dd>{run.startedAt ? new Date(run.startedAt).toLocaleString() : "—"}</dd>
          </div>
          <div>
            <dt>Finished</dt>
            <dd>{run.finishedAt ? relativeTime(run.finishedAt) : "—"}</dd>
          </div>
        </dl>

        {run.errorMessage && (
          <pre className="output error" role="alert">
            {run.errorMessage}
          </pre>
        )}

        {run.hasApk && (
          <div className="row" style={{ marginTop: 12, alignItems: "center", gap: 10, flexWrap: "wrap" }}>
            <a className="md-button filled" href={`/api/runs/${run.id}/apk`}>
              <Icon name="external" size={18} />
              Download the APK
            </a>
            <span className="md-body-sm muted">
              one-time link — it works once and then the file is gone
            </span>
          </div>
        )}

        {!run.hasApk && run.apkDownloaded && (
          <p className="md-body-sm muted" style={{ marginTop: 12 }}>
            The APK for this run was already downloaded; the one-time link is spent.
          </p>
        )}

        {(run.status === "running" || run.status === "queued") && (
          <div className="row" style={{ marginTop: 12 }}>
            <button
              type="button"
              className="md-button danger"
              onClick={() => void cancelRun()}
              disabled={cancelling}
            >
              {cancelling ? "Stopping…" : "Stop this run"}
            </button>
          </div>
        )}
      </section>

      {run.platform === "android" && (
        <LiveView runId={run.id} running={run.status === "running"} hasFrame={run.hasLiveFrame} />
      )}

      <section className="panel">
        <div className="panel-head">
          <h2>Steps</h2>
          <span className="panel-sub">
            {visibleSteps.filter((step) => step.status === "done").length} of {visibleSteps.length} done
          </span>
        </div>

        <ol className="stepper">
          {visibleSteps.map((step) => (
            <li key={step.key} className={`step ${statusTone(step.status)}`}>
              <div className="step-rail">
                <span className="step-marker">{stepGlyph(step.status)}</span>
                <span className="step-line" aria-hidden="true" />
              </div>
              <div className="step-body">
                <div className="step-head">
                  <span className="step-name">{step.label}</span>
                  <span className="step-time">
                    {step.finishedAt
                      ? new Date(step.finishedAt).toLocaleTimeString()
                      : step.status === "running"
                        ? "running…"
                        : ""}
                  </span>
                </div>

                <StepResults step={step} />

                {step.output && step.output.trim() !== "" && (
                  <details className="disclosure">
                    <summary>Show output</summary>
                    <pre className="output">{step.output}</pre>
                  </details>
                )}
              </div>
            </li>
          ))}
        </ol>
      </section>

      {run.hasScreenshot && (
        <ScreenshotPanel
          runId={run.id}
          kind="screenshot"
          title="App at launch"
          note="What the app looked like the moment it came up, before any flow touched it."
        />
      )}

      {/* When the run was watched live, the last frame the recorder captured is the
          failing screen itself, so Maestro's own capture of that moment would only
          repeat it. Without live frames — the recorder failed, or the run stopped
          before the flows — the screenshot is the only view of it there is. */}
      {run.hasMaestroScreenshot && !(run.live && run.hasLiveFrame) && (
        <ScreenshotPanel
          runId={run.id}
          kind="maestro-screenshot"
          title="Maestro failure"
          note="The screen Maestro was looking at when its assertion failed."
        />
      )}

      {run.hasAiScreenshot && (
        <ScreenshotPanel
          runId={run.id}
          kind="ai-screenshot"
          title="AI failure"
          note="The screen the AI lane captured and analysed when a step did not land."
        />
      )}

      {run.hasAiReport && <AiReportPanel runId={run.id} />}

      <div className="row">
        <Link href="/projects" className="md-button tonal">
          <Icon name="add" size={18} />
          Start another test
        </Link>
        <Link href="/runs" className="md-button text">
          All runs
        </Link>
      </div>
    </>
  );
}
