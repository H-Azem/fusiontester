"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";

import { Bird, BIRD_FOR_STATUS, type BirdState } from "./bird";
import { Icon } from "./icons";
import { StatusChip, relativeTime, shortDuration, statusTone } from "./status";
import type { RunSummary } from "./use-runs";

/** The run's live pose, so status needs no reading. */
export function statusBird(run: RunSummary): BirdState {
  return BIRD_FOR_STATUS[run.status] ?? "unknown";
}

const KIND_LABELS: Record<string, string> = { ai: "AI", maestro: "Maestro", manual: "Manual APK" };

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

function methods(run: RunSummary): string {
  return run.runKinds.map((kind) => KIND_LABELS[kind] ?? kind).join(" · ") || "—";
}

/** Rows become cards on touch widths: the same data, laid out for a thumb. */
export function RunsTable({ runs }: { runs: RunSummary[] }) {
  const router = useRouter();

  return (
    <>
      <div className="table-wrap desktop-only">
        <table className="table">
          <thead>
            <tr>
              <th>Status</th>
              <th>Repository</th>
              <th>Branch</th>
              <th>Tests</th>
              <th>Method</th>
              <th>Current step</th>
              <th>Took</th>
            </tr>
          </thead>
          <tbody>
            {runs.map((run) => {
              const step = run.steps.find((candidate) => candidate.key === run.currentStep);
              return (
                <tr key={run.id} onClick={() => router.push(`/runs/${run.id}`)}>
                  <td>
                    <StatusChip status={run.status} />
                  </td>
                  <td>
                    <span className="cell-path">
                      <strong>{run.projectPath.split("/").slice(-1)[0]}</strong>
                      <span className="cell-sub">{run.projectPath.split("/").slice(0, -1).join("/")}</span>
                    </span>
                  </td>
                  <td className="mono">{run.branch}</td>
                  <td>
                    {run.tests.length > 0 ? run.tests.map(testLabel).join(", ") : <span className="faint">all</span>}
                  </td>
                  <td>
                    <span className="cell-path">
                      <strong>{methods(run)}</strong>
                      {run.apkDownloaded && <span className="cell-sub">APK downloaded</span>}
                    </span>
                  </td>
                  <td className="muted">{step?.label ?? run.currentStep}</td>
                  <td className="mono muted">
                    {shortDuration(run.startedAt, run.finishedAt) ?? (run.startedAt ? "running" : "—")}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <div className="list mobile-only">
        {runs.map((run) => (
          <Link key={run.id} href={`/runs/${run.id}`} className="list-item">
            <Bird state={statusBird(run)} size={32} />
            <span className="list-item-main">
              <span className="list-item-title">{run.projectPath.split("/").slice(-1)[0]}</span>
              <span className="list-item-sub">
                {run.tests.length > 0 ? run.tests.map(testLabel).join(", ") : "all tests"} · {run.branch}
              </span>
              <span className="list-item-sub">
                {methods(run)}
                {run.apkDownloaded ? " · APK downloaded" : ""}
              </span>
              <span className="list-item-sub">
                {run.startedAt ? relativeTime(run.startedAt) : "queued"}
                {run.status === "running" ? " · in flight" : ""}
              </span>
            </span>
            <span className={`status ${statusTone(run.status)}`}>
              <span className="dot" aria-hidden="true" />
              {run.status}
            </span>
            <Icon name="chevronRight" size={18} />
          </Link>
        ))}
      </div>
    </>
  );
}
