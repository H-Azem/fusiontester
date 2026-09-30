"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import { useRun } from "./use-runs";
import { StatusBadge, statusClass } from "./runs-table";

const STEP_MARK: Record<string, string> = {
  pending: "○",
  running: "●",
  done: "✓",
  failed: "✗",
  skipped: "–",
};

/** How often a live frame lands, matching the capture interval on the server. */
const LIVE_REFRESH_MS = 2500;

/**
 * The device screen while a run is in flight. The frame is overwritten in place,
 * so the query string is what forces the browser to ask for the new one; after
 * the run ends the last frame stays put until the app repaints.
 */
function LiveView({ runId, running, hasFrame }: { runId: string; running: boolean; hasFrame: boolean }) {
  const [tick, setTick] = useState(0);
  const [missing, setMissing] = useState(false);

  useEffect(() => {
    if (!running) return;
    const timer = setInterval(() => setTick((value) => value + 1), LIVE_REFRESH_MS);
    return () => clearInterval(timer);
  }, [running]);

  if (!running && !hasFrame) return null;

  return (
    <section className="panel">
      <div className="panel-head">
        <h2>Live device</h2>
        <a
          href={`/api/runs/${runId}/live?t=${tick}`}
          target="_blank"
          rel="noreferrer"
          className="link"
        >
          Open full size
        </a>
      </div>

      {missing ? (
        <p className="muted">Waiting for the first frame…</p>
      ) : (
        // eslint-disable-next-line @next/next/no-img-element
        <img
          className="screenshot"
          src={`/api/runs/${runId}/live?t=${tick}`}
          alt="The device screen as the test runs"
          onError={() => setMissing(true)}
          onLoad={() => setMissing(false)}
        />
      )}
    </section>
  );
}

export function RunDetail({ id }: { id: string }) {
  const { run, error, loaded } = useRun(id);

  if (error) {
    return (
      <>
        <p className="error">{error}</p>
        <p>
          <Link href="/runs" className="link">
            Back to test runs
          </Link>
        </p>
      </>
    );
  }

  if (!loaded || !run) return <p className="muted">Loading…</p>;

  return (
    <>
      <p className="breadcrumb">
        <Link href="/runs" className="link">
          Test runs
        </Link>
        <span className="muted"> / {run.projectPath}</span>
      </p>

      <div className="detail-head">
        <StatusBadge status={run.status} />
        <span className="detail-path">{run.projectPath}</span>
        <span className="muted mono">{run.branch}</span>
      </div>

      <dl className="meta-grid">
        <div>
          <dt>Tests</dt>
          <dd>{run.tests.length > 0 ? run.tests.join(", ") : "—"}</dd>
        </div>
        <div>
          <dt>Run with</dt>
          <dd>{run.runKinds.join(" + ") || "—"}</dd>
        </div>
        <div>
          <dt>Environment</dt>
          <dd>{run.environments.join(" + ") || "—"}</dd>
        </div>
        <div>
          <dt>Orientation</dt>
          <dd>{run.orientation === "vertical" ? "Vertical" : "Horizontal"}</dd>
        </div>
        <div>
          <dt>Runs on</dt>
          <dd>
            {run.platform === "android" ? "Android device" : "Web"}
            {run.live ? " · live view" : ""}
          </dd>
        </div>
        {run.dartDefines ? (
          <div>
            <dt>Build defines</dt>
            <dd className="mono">{`ENABLE_SEMANTICS=true ${run.dartDefines}`}</dd>
          </div>
        ) : null}
        <div>
          <dt>Started</dt>
          <dd>{run.startedAt ? new Date(run.startedAt).toLocaleString() : "—"}</dd>
        </div>
        <div>
          <dt>Finished</dt>
          <dd>{run.finishedAt ? new Date(run.finishedAt).toLocaleString() : "—"}</dd>
        </div>
      </dl>

      <section className="panel">
        <h2>Progress</h2>
        <ol className="run-steps">
          {run.steps.map((step) => (
            <li key={step.key} className={statusClass(step.status)}>
              <span className="mark">{STEP_MARK[step.status] ?? "○"}</span>
              <span className="step-label">{step.label}</span>
              <span className="muted step-time">
                {step.finishedAt
                  ? new Date(step.finishedAt).toLocaleTimeString()
                  : step.status === "running"
                    ? "running…"
                    : ""}
              </span>
              {step.output && <pre className="step-output">{step.output}</pre>}
            </li>
          ))}
        </ol>

        {run.errorMessage && <p className="error">{run.errorMessage}</p>}
      </section>

      {run.platform === "android" && (
        <LiveView runId={run.id} running={run.status === "running"} hasFrame={run.hasLiveFrame} />
      )}

      {run.hasScreenshot && (
        <section className="panel">
          <div className="panel-head">
            <h2>App at launch</h2>
            <a
              href={`/api/runs/${run.id}/screenshot`}
              target="_blank"
              rel="noreferrer"
              className="link"
            >
              Open full size
            </a>
          </div>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            className="screenshot"
            src={`/api/runs/${run.id}/screenshot`}
            alt="The app as it appeared when it loaded"
          />
        </section>
      )}

      {run.hasMaestroScreenshot && (
        <section className="panel">
          <div className="panel-head">
            <h2>Maestro failure</h2>
            <a
              href={`/api/runs/${run.id}/maestro-screenshot`}
              target="_blank"
              rel="noreferrer"
              className="link"
            >
              Open full size
            </a>
          </div>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            className="screenshot"
            src={`/api/runs/${run.id}/maestro-screenshot`}
            alt="The app as Maestro left it when the test failed"
          />
        </section>
      )}

      {run.hasAiScreenshot && (
        <section className="panel">
          <div className="panel-head">
            <h2>AI failure</h2>
            <a
              href={`/api/runs/${run.id}/ai-screenshot`}
              target="_blank"
              rel="noreferrer"
              className="link"
            >
              Open full size
            </a>
          </div>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            className="screenshot"
            src={`/api/runs/${run.id}/ai-screenshot`}
            alt="The app as the AI agent found it when the step failed"
          />
        </section>
      )}
    </>
  );
}
