"use client";

import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";

type MaestroTest = {
  name: string;
  displayName: string;
  path: string;
  /** Whole-suite test: choosing it takes over from individual selections. */
  exclusive: boolean;
};

type RunKind = "maestro" | "ai";
type Orientation = "horizontal" | "vertical";

const RUN_KINDS: Array<{ id: RunKind; label: string }> = [
  { id: "maestro", label: "Maestro" },
  { id: "ai", label: "AI test" },
];

const ORIENTATIONS: Array<{ id: Orientation; label: string }> = [
  { id: "horizontal", label: "Horizontal" },
  { id: "vertical", label: "Vertical" },
];

export function RunConfiguration({
  projectId,
  projectPath,
  branch,
  tests,
}: {
  projectId: number;
  projectPath: string;
  branch: string;
  tests: MaestroTest[];
}) {
  const router = useRouter();
  const [selectedTests, setSelectedTests] = useState<Set<string>>(() => new Set<string>());
  const [runKinds, setRunKinds] = useState<Set<RunKind>>(
    () => new Set<RunKind>(["maestro"]),
  );
  // Development is always included, so only production is a real choice here.
  const [includeProduction, setIncludeProduction] = useState(false);
  // Remembered per repository, so the next run opens with the same choice.
  const [orientation, setOrientation] = useState<Orientation>("horizontal");
  // Extra --dart-define values this app needs; also remembered per repository.
  const [dartDefines, setDartDefines] = useState("");
  const [starting, setStarting] = useState(false);
  const [startError, setStartError] = useState<string | null>(null);

  const exclusiveSelected = useMemo(
    () => tests.some((test) => test.exclusive && selectedTests.has(test.name)),
    [tests, selectedTests],
  );

  useEffect(() => {
    let cancelled = false;

    fetch(`/api/projects/${projectId}/settings`, { cache: "no-store" })
      .then((response) => (response.ok ? response.json() : null))
      .then((data: { orientation?: Orientation; dartDefines?: string } | null) => {
        if (cancelled || !data) return;
        if (data.orientation) setOrientation(data.orientation);
        if (typeof data.dartDefines === "string") setDartDefines(data.dartDefines);
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, [projectId]);

  function toggleTest(test: MaestroTest) {
    setSelectedTests((current) => {
      if (current.has(test.name)) {
        const next = new Set(current);
        next.delete(test.name);
        return next;
      }

      // A whole-suite test supersedes any individual selection.
      if (test.exclusive) {
        return new Set([test.name]);
      }

      const next = new Set(current);
      next.add(test.name);
      return next;
    });
  }

  function toggleRunKind(kind: RunKind) {
    setRunKinds((current) => {
      const next = new Set(current);
      if (next.has(kind)) {
        // At least one runner must stay selected.
        if (next.size === 1) return current;
        next.delete(kind);
      } else {
        next.add(kind);
      }
      return next;
    });
  }

  async function startTest() {
    setStarting(true);
    setStartError(null);

    try {
      const response = await fetch("/api/runs", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          projectId,
          projectPath,
          branch,
          tests: [...selectedTests],
          runKinds: [...runKinds],
          environments: includeProduction
            ? ["development", "production"]
            : ["development"],
          orientation,
          dartDefines,
        }),
      });

      const data = (await response.json()) as { id?: string; message?: string };

      if (!response.ok || !data.id) {
        setStartError(data.message ?? "Could not queue the run.");
        return;
      }

      // The run's progress lives on its own page, so go straight there.
      router.push(`/runs/${data.id}`);
    } catch (err) {
      setStartError(err instanceof Error ? err.message : String(err));
    } finally {
      setStarting(false);
    }
  }

  const summary = [
    `${selectedTests.size} test${selectedTests.size === 1 ? "" : "s"}`,
    RUN_KINDS.filter((kind) => runKinds.has(kind.id))
      .map((kind) => kind.label)
      .join(" + "),
    includeProduction ? "Development + Production" : "Development",
    orientation === "horizontal" ? "Horizontal" : "Vertical",
  ].join(" · ");

  return (
    <div className="run-config">
      <div className="tests">
        <h3>
          Tests <span className="muted">{summary}</span>
        </h3>

        <ul>
          {tests.map((test) => {
            const isSelected = selectedTests.has(test.name);
            const isDisabled = exclusiveSelected && !test.exclusive;

            return (
              <li key={test.name}>
                <label className={isDisabled ? "test-option disabled" : "test-option"}>
                  <input
                    type="checkbox"
                    checked={isSelected}
                    disabled={isDisabled}
                    onChange={() => toggleTest(test)}
                  />
                  <span className="test-name">{test.displayName}</span>
                  {test.exclusive && <span className="tag">runs everything</span>}
                </label>
                <span className="muted test-path">{test.path}</span>
              </li>
            );
          })}
        </ul>

        {exclusiveSelected && (
          <p className="muted">
            A whole-suite test covers every flow, so individual tests are disabled while it is
            selected.
          </p>
        )}
      </div>

      <div className="options">
        <fieldset>
          <legend>Run with</legend>
          {RUN_KINDS.map((kind) => (
            <label key={kind.id}>
              <input
                type="checkbox"
                checked={runKinds.has(kind.id)}
                onChange={() => toggleRunKind(kind.id)}
              />
              <span>{kind.label}</span>
            </label>
          ))}
        </fieldset>

        <fieldset>
          <legend>Environment</legend>
          <label className="locked">
            <input type="checkbox" checked disabled readOnly />
            <span>Development</span>
            <span className="muted">always</span>
          </label>
          <label>
            <input
              type="checkbox"
              checked={includeProduction}
              onChange={() => setIncludeProduction((current) => !current)}
            />
            <span>Production</span>
          </label>
        </fieldset>

        <fieldset>
          <legend>Orientation</legend>
          {ORIENTATIONS.map((item) => (
            <label key={item.id}>
              <input
                type="radio"
                name={`orientation-${projectId}`}
                checked={orientation === item.id}
                onChange={() => setOrientation(item.id)}
              />
              <span>{item.label}</span>
            </label>
          ))}
        </fieldset>

        <label className="field">
          <span>Extra build defines</span>
          <input
            value={dartDefines}
            onChange={(event) => setDartDefines(event.target.value)}
            placeholder="ENABLE_DEV_TOOLS=true"
            spellCheck={false}
          />
        </label>
      </div>

      <div className="actions">
        <button
          type="button"
          onClick={() => void startTest()}
          disabled={starting || selectedTests.size === 0}
        >
          {starting ? "Starting…" : "Start test"}
        </button>
        {selectedTests.size === 0 && (
          <span className="muted">Select at least one test to run.</span>
        )}
      </div>

      {startError && <p className="error">{startError}</p>}
    </div>
  );
}
