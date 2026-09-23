"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";

import type { RunSummary } from "./use-runs";

const STEP_MARK: Record<string, string> = {
  pending: "○",
  running: "●",
  done: "✓",
  failed: "✗",
};

export function statusClass(status: string): string {
  if (status === "done" || status === "passed") return "ok";
  if (status === "failed") return "fail";
  if (status === "running") return "live";
  return "pending";
}

export function StatusBadge({ status }: { status: string }) {
  return <span className={`run-status ${statusClass(status)}`}>{status}</span>;
}

function relativeTime(value: string): string {
  const diff = Date.now() - new Date(value).getTime();
  const minutes = Math.floor(diff / 60000);
  if (minutes < 1) return "just now";
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  return new Date(value).toLocaleDateString();
}

export function RunsTable({ runs }: { runs: RunSummary[] }) {
  const router = useRouter();

  return (
    <div className="table-wrap">
      <table className="table">
        <thead>
          <tr>
            <th>Status</th>
            <th>Project</th>
            <th>Branch</th>
            <th>Tests</th>
            <th>Current step</th>
            <th>Started</th>
          </tr>
        </thead>
        <tbody>
          {runs.map((run) => {
            const active = run.steps.find((step) => step.key === run.currentStep);
            return (
              <tr
                key={run.id}
                className="clickable"
                onClick={() => router.push(`/runs/${run.id}`)}
              >
                <td>
                  <StatusBadge status={run.status} />
                </td>
                <td>
                  <Link href={`/runs/${run.id}`} className="link">
                    {run.projectPath}
                  </Link>
                </td>
                <td className="mono">{run.branch}</td>
                <td>{run.tests.length}</td>
                <td>
                  <span className="step-cell">
                    <span className={`mark ${statusClass(active?.status ?? "")}`}>
                      {STEP_MARK[active?.status ?? "pending"] ?? "○"}
                    </span>
                    {active?.label ?? run.currentStep}
                  </span>
                </td>
                <td className="muted">{run.startedAt ? relativeTime(run.startedAt) : "—"}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
