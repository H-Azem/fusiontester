"use client";

import { useEffect, useState } from "react";

import { Bird } from "./bird";
import { Icon } from "./icons";
import { RunConfiguration } from "./run-configuration";

type BranchCheck = {
  ref: string;
  isFlutterApp: boolean;
  isFlutterAppReason: string;
  hasWebFolder: boolean;
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
 * A branch is only testable when it is a Flutter app with Maestro flows, so those
 * two facts are checked before anything else is offered. Mount with a key of
 * `${projectId}:${branch}` so switching branch re-checks.
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
        if (!cancelled) setError(err instanceof Error ? err.message : String(err));
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

  if (checking) {
    return (
      <div className="stack" aria-busy="true">
        <span className="skeleton" style={{ width: "55%" }} />
        <span className="skeleton" style={{ width: "40%" }} />
      </div>
    );
  }

  if (error) {
    return (
      <p className="snackbar error" role="alert">
        <Icon name="warning" size={18} />
        {error}
      </p>
    );
  }

  if (!check) return null;

  return (
    <div className="stack">
      <ul className="check-list">
        <li className={check.isFlutterApp ? "check ok" : "check fail"}>
          <span className="mark" aria-hidden="true">
            <Icon name={check.isFlutterApp ? "check" : "close"} size={13} />
          </span>
          <span>
            <span className="md-title-sm">Flutter application</span>
            <br />
            <span className="md-body-sm muted">{check.isFlutterAppReason}</span>
          </span>
        </li>
        <li className={check.hasMaestroFlows ? "check ok" : "check fail"}>
          <span className="mark" aria-hidden="true">
            <Icon name={check.hasMaestroFlows ? "check" : "close"} size={13} />
          </span>
          <span>
            <span className="md-title-sm">.maestro/flows present</span>
            <br />
            <span className="md-body-sm muted">{check.hasMaestroFlowsReason}</span>
          </span>
        </li>
      </ul>

      {!check.canContinue && (
        <p className="md-body-sm muted">
          Both conditions have to pass before this branch can be tested. Push the missing
          piece to the branch and check again.
        </p>
      )}

      {check.canContinue && !tests && (
        <button
          type="button"
          className="md-button tonal"
          onClick={() => void loadTests()}
          disabled={loadingTests}
        >
          {loadingTests ? <span className="spinner" /> : <Icon name="chevronRight" size={18} />}
          {loadingTests ? "Reading flows…" : "Continue"}
        </button>
      )}

      {tests && tests.length === 0 && (
        <div className="empty-state">
          <Bird state="not-found" size={88} />
          <h3 className="md-title">No flows found</h3>
          <p className="md-body">
            This branch has a .maestro folder but no flow folders inside it yet.
          </p>
        </div>
      )}

      {tests && tests.length > 0 && (
        <RunConfiguration
          projectId={projectId}
          projectPath={projectPath}
          branch={branch}
          tests={tests}
          hasWebFolder={check.hasWebFolder}
        />
      )}
    </div>
  );
}
