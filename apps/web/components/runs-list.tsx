"use client";

import Link from "next/link";

import { RunsTable } from "./runs-table";
import { useRuns } from "./use-runs";

export function RunsList({ limit }: { limit?: number }) {
  const { runs, error, loaded, reload } = useRuns();

  const inFlight = runs.filter((run) => run.status === "running" || run.status === "queued").length;
  const visible = limit ? runs.slice(0, limit) : runs;

  return (
    <>
      <div className="toolbar">
        <button type="button" onClick={() => void reload()}>
          Refresh
        </button>
        {runs.length > 0 && (
          <span className="muted">
            {runs.length} run{runs.length === 1 ? "" : "s"}
            {inFlight > 0 ? ` · ${inFlight} in flight` : ""}
          </span>
        )}
      </div>

      {error && <p className="error">{error}</p>}

      {loaded && runs.length === 0 ? (
        <div className="empty">
          <p>No runs yet.</p>
          <Link href="/projects" className="link">
            Choose an app and start a test
          </Link>
        </div>
      ) : visible.length > 0 ? (
        <RunsTable runs={visible} />
      ) : null}
    </>
  );
}
