"use client";

import Link from "next/link";

import { Bird } from "@/components/bird";
import { Icon } from "@/components/icons";
import { RunsTable } from "@/components/runs-table";
import { relativeTime } from "@/components/status";
import { useRuns } from "@/components/use-runs";

export default function DashboardPage() {
  const { runs, error, loaded } = useRuns();

  const inFlight = runs.filter((run) => run.status === "running" || run.status === "queued");
  const passed = runs.filter((run) => run.status === "passed").length;
  const failed = runs.filter((run) => run.status === "failed").length;
  const finished = passed + failed;
  const newest = runs[0];

  const stats = [
    {
      label: "In flight",
      value: inFlight.length,
      hint: inFlight.length > 0 ? `${inFlight.filter((run) => run.status === "running").length} running` : "nothing queued",
      tone: inFlight.length > 0 ? "is-live" : "",
      icon: "live",
    },
    {
      label: "Passed",
      value: passed,
      hint: finished > 0 ? `of ${finished} finished` : "no finished runs",
      tone: passed > 0 ? "is-pass" : "",
      icon: "check",
    },
    {
      label: "Failed",
      value: failed,
      hint: failed > 0 ? "needs a look" : "none",
      tone: failed > 0 ? "is-fail" : "",
      icon: "error",
    },
    {
      label: "Pass rate",
      value: finished > 0 ? `${Math.round((passed / finished) * 100)}%` : "—",
      hint: finished > 0 ? `${passed} of ${finished}` : "nothing measured yet",
      tone: "",
      icon: "runs",
    },
  ];

  return (
    <>
      {error && (
        <p className="snackbar error" role="alert">
          <Icon name="warning" size={18} />
          {error}
        </p>
      )}

      <section className="stats" aria-label="Run summary">
        {stats.map((stat) => (
          <article key={stat.label} className={`card stat ${stat.tone}`}>
            <div className="stat-top">
              <span className="card-title">{stat.label}</span>
              <span className={`stat-icon ${stat.tone}`} aria-hidden="true">
                <Icon name={stat.icon} size={18} />
              </span>
            </div>
            <span className={`stat-value ${stat.tone}`}>{stat.value}</span>
            <span className="stat-hint">{stat.hint}</span>
          </article>
        ))}
      </section>

      {inFlight.length > 0 && (
        <section className="panel" aria-label="Running now">
          <div className="panel-head">
            <h2>Running now</h2>
            <Link href="/runs" className="md-button text small">
              All runs
              <Icon name="chevronRight" size={16} />
            </Link>
          </div>
          <div className="list">
            {inFlight.map((run) => (
              <Link key={run.id} href={`/runs/${run.id}`} className="list-item">
                <Bird state={run.status === "running" ? "processing" : "loading"} size={36} />
                <span className="list-item-main">
                  <span className="list-item-title">
                    {run.projectPath.split("/").slice(-1)[0]} · {run.branch}
                  </span>
                  <span className="list-item-sub">
                    {run.steps.find((step) => step.key === run.currentStep)?.label ?? run.currentStep}
                    {run.startedAt ? ` · started ${relativeTime(run.startedAt)}` : ""}
                  </span>
                </span>
                <Icon name="chevronRight" size={18} />
              </Link>
            ))}
          </div>
        </section>
      )}

      <section className="panel">
        <div className="panel-head">
          <h2>Recent runs</h2>
          {runs.length > 0 && (
            <Link href="/runs" className="md-button text small">
              View all
              <Icon name="chevronRight" size={16} />
            </Link>
          )}
        </div>

        {!loaded ? (
          <div className="stack" aria-busy="true">
            <span className="skeleton" style={{ width: "60%" }} />
            <span className="skeleton" style={{ width: "45%" }} />
            <span className="skeleton" style={{ width: "52%" }} />
          </div>
        ) : runs.length === 0 ? (
          <div className="empty-state">
            <Bird state="empty" size={120} float />
            <h3 className="md-title">No runs yet</h3>
            <p className="md-body">
              Pick a repository and a branch, choose which flows to drive, then start a test.
              Everything it does shows up here step by step.
            </p>
            <Link href="/projects" className="md-button filled">
              <Icon name="apps" size={18} />
              Browse test apps
            </Link>
          </div>
        ) : (
          <>
            <RunsTable runs={runs.slice(0, 5)} />
            {newest && (
              <p className="md-body-sm faint">
                Last run {newest.startedAt ? relativeTime(newest.startedAt) : "queued"} ·{" "}
                {newest.projectPath}
              </p>
            )}
          </>
        )}
      </section>
    </>
  );
}
