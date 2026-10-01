"use client";

import Link from "next/link";
import { useMemo, useState } from "react";

import { Bird } from "./bird";
import { Icon } from "./icons";
import { RunsTable } from "./runs-table";
import { useRuns } from "./use-runs";

const FILTERS = [
  { id: "all", label: "All" },
  { id: "running", label: "In flight" },
  { id: "passed", label: "Passed" },
  { id: "failed", label: "Failed" },
] as const;

type FilterId = (typeof FILTERS)[number]["id"];

/**
 * One search field and four chips, because "where is that run" is the only
 * question this screen answers. The chips carry their own counts so the state of
 * the queue is readable before anything is typed.
 */
export function RunsList() {
  const { runs, error, loaded, reload } = useRuns();
  const [filter, setFilter] = useState<FilterId>("all");
  const [query, setQuery] = useState("");
  const [refreshing, setRefreshing] = useState(false);

  const counts = useMemo(
    () => ({
      all: runs.length,
      running: runs.filter((run) => run.status === "running" || run.status === "queued").length,
      passed: runs.filter((run) => run.status === "passed").length,
      failed: runs.filter((run) => run.status === "failed").length,
    }),
    [runs],
  );

  const visible = useMemo(() => {
    const needle = query.trim().toLowerCase();

    return runs.filter((run) => {
      const matchesFilter =
        filter === "all"
          ? true
          : filter === "running"
            ? run.status === "running" || run.status === "queued"
            : run.status === filter;

      const matchesQuery =
        needle === "" ||
        run.projectPath.toLowerCase().includes(needle) ||
        run.branch.toLowerCase().includes(needle) ||
        run.tests.some((test) => test.toLowerCase().includes(needle));

      return matchesFilter && matchesQuery;
    });
  }, [runs, filter, query]);

  async function refresh() {
    setRefreshing(true);
    try {
      await reload();
    } finally {
      setRefreshing(false);
    }
  }

  return (
    <>
      <div className="toolbar">
        <label className="search-field">
          <span className="search-icon">
            <Icon name="search" size={18} />
          </span>
          <input
            type="search"
            placeholder="Search repository, branch or test"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            aria-label="Search runs"
          />
        </label>
        <button
          type="button"
          className="md-button tonal"
          onClick={() => void refresh()}
          disabled={refreshing}
        >
          {refreshing ? <span className="spinner" /> : <Icon name="refresh" size={18} />}
          Refresh
        </button>
      </div>

      <div className="chip-row" role="tablist" aria-label="Filter runs">
        {FILTERS.map((item) => (
          <button
            key={item.id}
            type="button"
            role="tab"
            aria-selected={filter === item.id}
            className={filter === item.id ? "chip selected" : "chip"}
            onClick={() => setFilter(item.id)}
          >
            {item.label}
            <span className="count">{counts[item.id]}</span>
          </button>
        ))}
      </div>

      {error && (
        <p className="snackbar error" role="alert">
          <Icon name="warning" size={18} />
          {error}
        </p>
      )}

      {!loaded ? (
        <div className="stack" aria-busy="true">
          <span className="skeleton" style={{ height: 56 }} />
          <span className="skeleton" style={{ height: 56 }} />
          <span className="skeleton" style={{ height: 56 }} />
        </div>
      ) : runs.length === 0 ? (
        <div className="empty-state">
          <Bird state="empty" size={120} float />
          <h3 className="md-title">No runs yet</h3>
          <p className="md-body">
            A run is one pass over an app: clone, build, drive it with Maestro, collect the
            screenshots. Start the first one from a test app.
          </p>
          <Link href="/projects" className="md-button filled">
            <Icon name="apps" size={18} />
            Browse test apps
          </Link>
        </div>
      ) : visible.length === 0 ? (
        <div className="empty-state">
          <Bird state="not-found" size={96} />
          <h3 className="md-title">Nothing matches</h3>
          <p className="md-body">
            No run matches that filter and search. Clear the search or pick another chip.
          </p>
          <button
            type="button"
            className="md-button tonal"
            onClick={() => {
              setQuery("");
              setFilter("all");
            }}
          >
            Clear filters
          </button>
        </div>
      ) : (
        <RunsTable runs={visible} />
      )}
    </>
  );
}
