"use client";

import { useCallback, useEffect, useState } from "react";

export type RunStep = {
  key: string;
  label: string;
  status: string;
  output: string | null;
  startedAt: string | null;
  finishedAt: string | null;
};

export type RunSummary = {
  id: string;
  projectId: number;
  projectPath: string;
  branch: string;
  tests: string[];
  runKinds: string[];
  environments: string[];
  status: string;
  currentStep: string;
  errorMessage: string | null;
  createdAt: string;
  startedAt: string | null;
  finishedAt: string | null;
  hasScreenshot: boolean;
  steps: RunStep[];
};

const POLL_INTERVAL_MS = 2000;

/**
 * Runs execute on the server, so polling keeps every page current even after
 * the tab was closed and reopened.
 */
export function useRuns() {
  const [runs, setRuns] = useState<RunSummary[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);

  const load = useCallback(async () => {
    try {
      const response = await fetch("/api/runs", { cache: "no-store" });
      if (!response.ok) return;
      const data = (await response.json()) as { runs?: RunSummary[] };
      setRuns(data.runs ?? []);
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setLoaded(true);
    }
  }, []);

  useEffect(() => {
    void load();
    const timer = setInterval(() => {
      void load();
    }, POLL_INTERVAL_MS);
    return () => clearInterval(timer);
  }, [load]);

  return { runs, error, loaded, reload: load };
}

/** Same polling, for a single run. */
export function useRun(id: string) {
  const [run, setRun] = useState<RunSummary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);

  const load = useCallback(async () => {
    try {
      const response = await fetch(`/api/runs/${id}`, { cache: "no-store" });
      if (response.status === 404) {
        setError("That run no longer exists.");
        return;
      }
      if (!response.ok) return;
      setRun((await response.json()) as RunSummary);
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setLoaded(true);
    }
  }, [id]);

  useEffect(() => {
    void load();
    const timer = setInterval(() => {
      void load();
    }, POLL_INTERVAL_MS);
    return () => clearInterval(timer);
  }, [load]);

  return { run, error, loaded, reload: load };
}
