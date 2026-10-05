"use client";

import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";

import { Icon } from "./icons";

type MaestroTest = {
  name: string;
  displayName: string;
  path: string;
  /** Whole-suite test: choosing it takes over from individual selections. */
  exclusive: boolean;
};

type RunKind = "maestro" | "ai";
type Orientation = "horizontal" | "vertical";
type Platform = "web" | "android";

const RUN_KINDS: Array<{ id: RunKind; label: string; hint: string }> = [
  { id: "maestro", label: "Maestro flows", hint: "drives the recorded journeys" },
  { id: "ai", label: "AI test", hint: "model picks the actions (web lane only)" },
];

const PLATFORMS: Array<{ id: Platform; label: string; hint: string }> = [
  { id: "android", label: "Android device", hint: "redroid emulator" },
  { id: "web", label: "Web", hint: "browser build" },
];

const ORIENTATIONS: Array<{ id: Orientation; label: string; icon: string; hint: string }> = [
  { id: "horizontal", label: "Landscape", icon: "landscape", hint: "wider than tall" },
  { id: "vertical", label: "Portrait", icon: "portrait", hint: "taller than wide" },
];

/**
 * Starting a run is a decision, not a form to scroll past, so it lives in a
 * Material sheet: the repository and branch stay visible behind it, and on a phone
 * it rises from the bottom into the thumb zone.
 *
 * The shape and the lane are remembered per repository, so the second visit opens
 * on the answer the first one gave.
 */
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
  const [open, setOpen] = useState(false);
  const [selectedTests, setSelectedTests] = useState<Set<string>>(() => new Set<string>());
  const [openGroups, setOpenGroups] = useState<Set<string>>(() => new Set<string>());

  // Tests that live under a folder are grouped by it, because a branch can carry
  // enough of them to bury the picker. Anything without a folder stays at the top.
  const { rootTests, testGroups } = useMemo(() => {
    const root: MaestroTest[] = [];
    const groups = new Map<string, { name: string; label: string; tests: MaestroTest[]; selected: number }>();

    for (const test of tests) {
      const slash = test.name.indexOf("/");

      if (slash === -1) {
        root.push(test);
        continue;
      }

      const name = test.name.slice(0, slash);
      const label = test.displayName.split(" · ")[0] || name;
      const group = groups.get(name) ?? { name, label, tests: [], selected: 0 };

      group.tests.push(test);

      if (selectedTests.has(test.name)) {
        group.selected += 1;
      }

      groups.set(name, group);
    }

    return { rootTests: root, testGroups: [...groups.values()] };
  }, [tests, selectedTests]);

  // Choosing a test opens the folder it lives in, so a selection is never hidden.
  useEffect(() => {
    setOpenGroups((current) => {
      const next = new Set(current);
      let changed = false;

      for (const test of tests) {
        const slash = test.name.indexOf("/");

        if (slash !== -1 && selectedTests.has(test.name) && !next.has(test.name.slice(0, slash))) {
          next.add(test.name.slice(0, slash));
          changed = true;
        }
      }

      return changed ? next : current;
    });
  }, [selectedTests, tests]);

  function setGroupOpen(name: string, open: boolean) {
    setOpenGroups((current) => {
      const next = new Set(current);

      if (open) {
        next.add(name);
      } else {
        next.delete(name);
      }

      return next;
    });
  }
  const [runKinds, setRunKinds] = useState<Set<RunKind>>(() => new Set<RunKind>(["maestro"]));
  const [includeProduction, setIncludeProduction] = useState(false);
  const [orientation, setOrientation] = useState<Orientation>("horizontal");
  const [platform, setPlatform] = useState<Platform>("android");
  const [live, setLive] = useState(true);
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
      .then(
        (
          data:
            | {
                orientation?: Orientation;
                platform?: Platform;
                dartDefines?: string;
                orientationSet?: boolean;
                platformSet?: boolean;
              }
            | null,
        ) => {
          if (cancelled || !data) return;
          if (data.orientation) setOrientation(data.orientation);
          // The apps under test are built for Android and the device lane is the one
          // that drives them the way they were written, so it is the default until
          // someone chooses otherwise for this repository.
          if (data.platformSet && data.platform) setPlatform(data.platform);
          else setPlatform("android");
          if (typeof data.dartDefines === "string") setDartDefines(data.dartDefines);
        },
      )
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, [projectId]);

  /** Remember a choice for the next run; a failure here must not block the form. */
  function remember(values: { orientation?: Orientation; platform?: Platform }) {
    void fetch(`/api/projects/${projectId}/settings`, {
      method: "PUT",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(values),
    }).catch(() => undefined);
  }

  useEffect(() => {
    if (!open) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") setOpen(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open]);

  function toggleTest(test: MaestroTest) {
    setSelectedTests((current) => {
      if (current.has(test.name)) {
        const next = new Set(current);
        next.delete(test.name);
        return next;
      }

      // A whole-suite test supersedes any individual selection.
      if (test.exclusive) return new Set([test.name]);

      const next = new Set(current);
      next.add(test.name);
      return next;
    });
  }

  function toggleRunKind(kind: RunKind) {
    setRunKinds((current) => {
      const next = new Set(current);
      if (next.has(kind)) {
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
          environments: includeProduction ? ["development", "production"] : ["development"],
          orientation,
          platform,
          live: platform === "android" && live,
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
    selectedTests.size === 0 ? "no tests picked" : `${selectedTests.size} selected`,
    runKinds.has("ai") ? "maestro + ai" : "maestro",
    platform === "android" ? "device" : "web",
  ].join(" · ");

  return (
    <>
      <div className="toolbar">
        <button type="button" className="md-button filled" onClick={() => setOpen(true)}>
          <Icon name="add" size={18} />
          Configure and start
        </button>
        <span className="faint md-body-sm">{summary}</span>
      </div>

      {open && (
        <div
          className="scrim"
          role="dialog"
          aria-modal="true"
          aria-label="Start a test"
          onClick={(event) => {
            if (event.target === event.currentTarget) setOpen(false);
          }}
        >
          <div className="sheet">
            <div className="sheet-head">
              <div>
                <h2 className="md-title-lg">Start a test</h2>
                <p className="md-body-sm muted">
                  {projectPath} · <span className="mono">{branch}</span>
                </p>
              </div>
              <button
                type="button"
                className="md-button icon"
                aria-label="Close"
                onClick={() => setOpen(false)}
              >
                <Icon name="close" size={20} />
              </button>
            </div>

            <div className="sheet-body">
              <div className="stack">
                <h3 className="md-title-sm">1 · Which tests</h3>

                {/* One renderer for a test, used at the top level and inside a folder,
                    so the two can never drift apart. */}
                {(() => {
                  const renderChoice = (test: MaestroTest) => {
                    const isSelected = selectedTests.has(test.name);
                    const isDisabled = exclusiveSelected && !test.exclusive;

                    return (
                      <li key={test.name}>
                        <label className={isDisabled ? "choice disabled" : "choice"}>
                          <input
                            type="checkbox"
                            checked={isSelected}
                            disabled={isDisabled}
                            onChange={() => toggleTest(test)}
                          />
                          <span className="list-item-main">
                            <span className="list-item-title">{test.displayName}</span>
                            <span className="list-item-sub mono">{test.path}</span>
                          </span>
                          {test.exclusive && <span className="status queued">runs everything</span>}
                        </label>
                      </li>
                    );
                  };

                  return (
                    <>
                      {rootTests.length > 0 && (
                        <ul
                          className="tests list"
                          style={{ listStyle: "none", margin: 0, padding: 0 }}
                        >
                          {rootTests.map(renderChoice)}
                        </ul>
                      )}

                      {testGroups.map((group) => (
                        <details
                          key={group.name}
                          open={openGroups.has(group.name)}
                          onToggle={(event) =>
                            setGroupOpen(
                              group.name,
                              (event.currentTarget as HTMLDetailsElement).open,
                            )
                          }
                        >
                          <summary className="md-title-sm">
                            {group.label}
                            <span className="muted">
                              {" · "}
                              {group.tests.length} test{group.tests.length === 1 ? "" : "s"}
                            </span>
                            {group.selected > 0 && (
                              <span className="chip selected">{group.selected} selected</span>
                            )}
                          </summary>

                          <ul
                            className="tests list"
                            style={{ listStyle: "none", margin: "6px 0 0", padding: "0 0 0 16px" }}
                          >
                            {group.tests.map(renderChoice)}
                          </ul>
                        </details>
                      ))}
                    </>
                  );
                })()}
                {exclusiveSelected && (
                  <p className="md-body-sm muted">
                    A whole-suite test covers every flow, so the individual tests are disabled
                    while it is selected.
                  </p>
                )}
              </div>

              <div className="stack">
                <h3 className="md-title-sm">2 · How</h3>

                <div className="chip-row" role="group" aria-label="Runners">
                  {RUN_KINDS.map((kind) => (
                    <button
                      key={kind.id}
                      type="button"
                      className={runKinds.has(kind.id) ? "chip selected" : "chip"}
                      aria-pressed={runKinds.has(kind.id)}
                      onClick={() => toggleRunKind(kind.id)}
                      title={kind.hint}
                    >
                      {kind.label}
                    </button>
                  ))}
                </div>

                <div className="chip-row" role="radiogroup" aria-label="Where to run">
                  {PLATFORMS.map((item) => (
                    <button
                      key={item.id}
                      type="button"
                      role="radio"
                      aria-checked={platform === item.id}
                      className={platform === item.id ? "chip selected" : "chip"}
                      onClick={() => {
                        setPlatform(item.id);
                        remember({ platform: item.id });
                      }}
                    >
                      <Icon name={item.id === "android" ? "device" : "bolt"} size={16} />
                      {item.label}
                      <span className="faint">{item.hint}</span>
                    </button>
                  ))}
                </div>

                <div className="chip-row" role="radiogroup" aria-label="Orientation">
                  {ORIENTATIONS.map((item) => (
                    <button
                      key={item.id}
                      type="button"
                      role="radio"
                      aria-checked={orientation === item.id}
                      className={orientation === item.id ? "chip selected" : "chip"}
                      title={item.hint}
                      onClick={() => {
                        setOrientation(item.id);
                        remember({ orientation: item.id });
                      }}
                    >
                      <Icon name={item.icon} size={16} />
                      {item.label}
                    </button>
                  ))}
                </div>

                <label className="switch">
                  <input
                    type="checkbox"
                    checked={live}
                    disabled={platform !== "android"}
                    onChange={() => setLive((current) => !current)}
                  />
                  <span>
                    <span className="md-title-sm">Live device view</span>
                    <br />
                    <span className="md-body-sm muted">
                      {platform === "android"
                        ? "a frame every 2.5s while the test runs"
                        : "only the device lane can be watched"}
                    </span>
                  </span>
                </label>

                <label className="switch">
                  <input
                    type="checkbox"
                    checked={includeProduction}
                    onChange={() => setIncludeProduction((current) => !current)}
                  />
                  <span>
                    <span className="md-title-sm">Also run production environment</span>
                    <br />
                    <span className="md-body-sm muted">development always runs</span>
                  </span>
                </label>
              </div>

              <details className="disclosure">
                <summary>Advanced · extra build defines</summary>
                <div className="stack" style={{ padding: "var(--space-4)" }}>
                  <label className="field">
                    <span className="field-label">Extra build defines</span>
                    <input
                      value={dartDefines}
                      onChange={(event) => setDartDefines(event.target.value)}
                      placeholder="KIOSK_IDLE_SECONDS=300"
                      spellCheck={false}
                    />
                    <span className="hint">
                      Values added to the app&apos;s compile time as <span className="mono">
                        --dart-define
                      </span>
                      . Only needed when an app reads a setting this way, for example a test
                      window it keeps open for Maestro. Nothing is required here for a normal
                      run, and it is remembered per repository.
                    </span>
                  </label>
                </div>
              </details>

              {startError && (
                <p className="snackbar error" role="alert">
                  <Icon name="warning" size={18} />
                  {startError}
                </p>
              )}
            </div>

            <div className="sheet-actions">
              <button type="button" className="md-button text" onClick={() => setOpen(false)}>
                Cancel
              </button>
              <button
                type="button"
                className="md-button filled"
                onClick={() => void startTest()}
                disabled={starting || selectedTests.size === 0}
              >
                {starting ? <span className="spinner" /> : <Icon name="runs" size={18} />}
                {starting ? "Queueing…" : "Start test"}
              </button>
            </div>

            {selectedTests.size === 0 && (
              <p className="md-body-sm muted">Pick at least one test to run.</p>
            )}
          </div>
        </div>
      )}
    </>
  );
}
