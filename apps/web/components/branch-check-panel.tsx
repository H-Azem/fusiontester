"use client";

import { useEffect, useState } from "react";

import { RunConfiguration } from "./run-configuration";

type BranchCheck = {
  ref: string;
  isFlutterApp: boolean;
  isFlutterAppReason: string;
  hasMaestroFlows: boolean;
  hasMaestroFlowsReason: string;
  canContinue: boolean;
};

type MaestroTest = {
  name: string;
  displayName: string;
  path: string;
  exclusive: boolean;
};

/**
 * Checks whether a branch is testable and, when it is, offers the test list.
 * Mount with a key of `${projectId}:${branch}` so switching branch re-checks.
 */
export function BranchCheckPanel({
  projectId,
  projectPath,
  branch,
}: {
  projectId: number;
  projectPath: string;
  branch: string;
}) {
  const [check, setCheck] = useState<BranchCheck | null>(null);
  const [tests, setTests] = useState<MaestroTest[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [checking, setChecking] = useState(true);
  const [loadingTests, setLoadingTests] = useState(false);

  useEffect(() => {
    let cancelled = false;

    async function run() {
      setChecking(true);
      setError(null);

      try {
        const response = await fetch(
          `/api/gitlab/projects/${projectId}/branch-check?ref=${encodeURIComponent(branch)}`,
          { cache: "no-store" },
        );
        const data = (await response.json()) as BranchCheck & { message?: string };

        if (cancelled) return;

        if (!response.ok) {
          setError(data.message ?? "Could not check this branch.");
          return;
        }

        setCheck(data);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : String(err));
        }
      } finally {
        if (!cancelled) setChecking(false);
      }
    }

    void run();

    return () => {
      cancelled = true;
    };
  }, [projectId, branch]);

  async function loadTests() {
    setLoadingTests(true);
    setError(null);

    try {
      const response = await fetch(
        `/api/gitlab/projects/${projectId}/tests?ref=${encodeURIComponent(branch)}`,
        { cache: "no-store" },
      );
      const data = (await response.json()) as { tests?: MaestroTest[]; message?: string };

      if (!response.ok) {
        setError(data.message ?? "Could not load tests.");
        return;
      }

      setTests(data.tests ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setLoadingTests(false);
    }
  }

  return (
    <div className="branch-check">
      <p className="check-title">
        Selected branch <strong>{branch}</strong>
      </p>

      {checking && <p className="muted">Checking this branch…</p>}
      {error && <p className="error">{error}</p>}

      {check && (
        <>
          <ul className="checks">
            <li className={check.isFlutterApp ? "check ok" : "check fail"}>
              <span className="mark">{check.isFlutterApp ? "✓" : "✗"}</span>
              <span>
                Flutter application
                <br />
                <span className="muted">{check.isFlutterAppReason}</span>
              </span>
            </li>
            <li className={check.hasMaestroFlows ? "check ok" : "check fail"}>
              <span className="mark">{check.hasMaestroFlows ? "✓" : "✗"}</span>
              <span>
                .maestro/flows present
                <br />
                <span className="muted">{check.hasMaestroFlowsReason}</span>
              </span>
            </li>
          </ul>

          {check.canContinue && !tests && (
            <button type="button" onClick={() => void loadTests()} disabled={loadingTests}>
              {loadingTests ? "Loading tests…" : "Continue"}
            </button>
          )}

          {!check.canContinue && (
            <p className="muted">
              Both conditions must pass before this branch can be tested.
            </p>
          )}
        </>
      )}

      {tests && (
        tests.length === 0 ? (
          <p className="muted">No test folders found.</p>
        ) : (
          <RunConfiguration
            projectId={projectId}
            projectPath={projectPath}
            branch={branch}
            tests={tests}
          />
        )
      )}
    </div>
  );
}
