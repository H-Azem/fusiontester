"use client";

import Link from "next/link";

import { RunsTable } from "@/components/runs-table";
import { useRuns } from "@/components/use-runs";

export default function DashboardPage() {
  const { runs, error, loaded } = useRuns();

  const running = runs.filter((run) => run.status === "running").length;
  const queued = runs.filter((run) => run.status === "queued").length;
  const passed = runs.filter((run) => run.status === "passed").length;
  const failed = runs.filter((run) => run.status === "failed").length;
  const finished = passed + failed;

  const cards = [
    {
      label: "In flight",
      value: running + queued,
      hint: `${running} running · ${queued} queued`,
      tone: running + queued > 0 ? "is-live" : "",
    },
    {
      label: "Passed",
      value: passed,
      hint: `of ${finished} finished`,
      tone: passed > 0 ? "is-pass" : "",
    },
    {
      label: "Failed",
      value: failed,
      hint: failed > 0 ? "needs attention" : "none",
      tone: failed > 0 ? "is-fail" : "",
    },
    {
      label: "Pass rate",
      value: finished > 0 ? `${Math.round((passed / finished) * 100)}%` : "—",
      hint: finished > 0 ? `${passed}/${finished}` : "no finished runs",
      tone: "",
    },
  ];

  return (
    <>
      {error && <p className="error">{error}</p>}

      <div className="cards">
        {cards.map((card) => (
          <div key={card.label} className={card.tone ? `card ${card.tone}` : "card"}>
            <span className="card-label">{card.label}</span>
            <span className="card-value">{card.value}</span>
            <span className="muted card-hint">{card.hint}</span>
          </div>
        ))}
      </div>

      <section className="panel">
        <div className="panel-head">
          <h2>Recent runs</h2>
          <Link href="/runs" className="link">
            View all
          </Link>
        </div>

        {loaded && runs.length === 0 ? (
          <div className="empty">
            <p>No runs yet.</p>
            <Link href="/projects" className="link">
              Choose an app and start a test
            </Link>
          </div>
        ) : runs.length > 0 ? (
          <RunsTable runs={runs.slice(0, 5)} />
        ) : null}
      </section>
    </>
  );
}
