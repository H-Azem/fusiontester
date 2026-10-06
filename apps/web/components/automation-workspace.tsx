"use client";

import { useCallback, useEffect, useMemo, useState } from "react";

import { Icon } from "@/components/icons";
import { StatusChip, relativeTime } from "@/components/status";

type Project = {
  id: number;
  name: string;
  pathWithNamespace: string;
  defaultBranch: string | null;
};

type Branch = { name: string; default: boolean };

type MaestroTest = { name: string; displayName: string; path: string; exclusive: boolean };

type BranchCheck = {
  ref: string;
  isFlutterApp: boolean;
  isFlutterAppReason: string;
  hasMaestroFlows: boolean;
  hasMaestroFlowsReason: string;
  canContinue: boolean;
};

type Trigger = {
  id: number;
  projectId: number;
  projectPath: string;
  branchPattern: string;
  tests: string[];
  orientation: string;
  platform: string;
  enabled: boolean;
  webhookUrl: string;
  lastFiredAt: string | null;
  lastFiredBranch: string | null;
  fireCount: number;
};

const LANES = [
  { id: "android", label: "Android device" },
  { id: "web", label: "Web" },
];

const ORIENTATIONS = [
  { id: "vertical", label: "Portrait" },
  { id: "horizontal", label: "Landscape" },
];

/**
 * The same journey as starting a test by hand — repository, branch, the flows on it —
 * except the last step writes a rule instead of pressing start. A push to that branch
 * then queues exactly this run by itself.
 *
 * Only a Flutter app with Maestro flows can be automated, which is why the branch is
 * checked the same way the manual flow checks it.
 */
export function AutomationWorkspace() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [branches, setBranches] = useState<Branch[]>([]);
  const [tests, setTests] = useState<MaestroTest[] | null>(null);
  const [check, setCheck] = useState<BranchCheck | null>(null);
  const [triggers, setTriggers] = useState<Trigger[]>([]);
  // Until the first answer arrives, an empty list means "not loaded", not "none".
  const [loaded, setLoaded] = useState(false);

  const [filter, setFilter] = useState("");
  const [project, setProject] = useState<Project | null>(null);
  const [branch, setBranch] = useState<string | null>(null);
  const [selectedTests, setSelectedTests] = useState<Set<string>>(() => new Set());
  const [platform, setPlatform] = useState("android");
  const [orientation, setOrientation] = useState("vertical");

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const loadTriggers = useCallback(async () => {
    const response = await fetch("/api/automation/triggers", { cache: "no-store" });

    if (response.status === 401) {
      window.location.href = "/login";
      return;
    }

    const data = (await response.json()) as { triggers?: Trigger[] };
    setTriggers(data.triggers ?? []);
    setLoaded(true);
  }, []);

  useEffect(() => {
    fetch("/api/gitlab/projects", { cache: "no-store" })
      .then((response) => (response.ok ? response.json() : { projects: [] }))
      .then((data: { projects?: Project[] }) => setProjects(data.projects ?? []))
      .catch(() => setProjects([]));
  }, []);

  useEffect(() => {
    void loadTriggers();
  }, [loadTriggers]);

  const visible = useMemo(() => {
    const needle = filter.trim().toLowerCase();
    const list = needle === ""
      ? projects
      : projects.filter((item) => item.pathWithNamespace.toLowerCase().includes(needle));

    return list.slice(0, 40);
  }, [projects, filter]);

  /** Picking a repository starts the same three questions the manual flow asks. */
  async function chooseProject(next: Project) {
    setProject(next);
    setBranch(next.defaultBranch);
    setBranches([]);
    setTests(null);
    setCheck(null);
    setSelectedTests(new Set());
    setError(null);

    const response = await fetch(`/api/gitlab/projects/${next.id}/branches`, { cache: "no-store" });
    const data = (await response.json()) as { branches?: Branch[] };
    setBranches(data.branches ?? []);
  }

  async function chooseBranch(ref: string) {
    if (!project) return;

    setBranch(ref);
    setTests(null);
    setCheck(null);
    setSelectedTests(new Set());
    setError(null);
    setLoading(true);

    try {
      const [checked, flows] = await Promise.all([
        fetch(
          `/api/gitlab/projects/${project.id}/branch-check?ref=${encodeURIComponent(ref)}`,
          { cache: "no-store" },
        ),
        fetch(
          `/api/gitlab/projects/${project.id}/tests?ref=${encodeURIComponent(ref)}`,
          { cache: "no-store" },
        ),
      ]);

      const checkData = (await checked.json()) as BranchCheck;
      const flowsData = (await flows.json()) as { tests?: MaestroTest[] };

      setCheck(checkData);

      if (!checkData.canContinue) {
        setTests([]);
        return;
      }

      const list = flowsData.tests ?? [];
      setTests(list);

      // A whole-suite test is the sensible default: automating a branch usually
      // means "tell me when this branch breaks", not "run one journey".
      const wholeSuite = list.find((test) => test.exclusive);
      setSelectedTests(new Set(wholeSuite ? [wholeSuite.name] : []));
    } finally {
      setLoading(false);
    }
  }

  function toggleTest(test: MaestroTest) {
    setSelectedTests((current) => {
      // A whole-suite test covers everything, so it stands alone.
      if (test.exclusive) {
        return current.has(test.name) ? new Set<string>() : new Set([test.name]);
      }

      const next = new Set([...current].filter((name) => !tests?.find((item) => item.name === name)?.exclusive));

      if (next.has(test.name)) next.delete(test.name);
      else next.add(test.name);

      return next;
    });
  }

  async function addRule() {
    if (!project || !branch) return;

    setSaving(true);
    setError(null);
    setNotice(null);

    try {
      const response = await fetch("/api/automation/triggers", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          projectId: project.id,
          projectPath: project.pathWithNamespace,
          branchPattern: branch,
          tests: [...selectedTests],
          runKinds: ["maestro"],
          environments: ["development"],
          platform,
          orientation,
        }),
      });

      const data = (await response.json()) as { message?: string };

      if (!response.ok) {
        setError(data.message ?? "Could not add the rule.");
        return;
      }

      setNotice(`Watching ${branch} in ${project.name}. Copy its webhook URL into GitLab.`);
      setProject(null);
      setBranch(null);
      setBranches([]);
      setTests(null);
      setCheck(null);
      setSelectedTests(new Set());
      setFilter("");
      await loadTriggers();
    } finally {
      setSaving(false);
    }
  }

  async function toggleRule(trigger: Trigger) {
    setTriggers((current) =>
      current.map((item) => (item.id === trigger.id ? { ...item, enabled: !item.enabled } : item)),
    );

    await fetch(`/api/automation/triggers/${trigger.id}`, {
      method: "PATCH",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ enabled: !trigger.enabled }),
    });

    await loadTriggers();
  }

  async function removeRule(trigger: Trigger) {
    await fetch(`/api/automation/triggers/${trigger.id}`, { method: "DELETE" });
    setNotice(`Stopped watching ${trigger.branchPattern} in ${trigger.projectPath}.`);
    await loadTriggers();
  }

  async function copy(trigger: Trigger) {
    try {
      await navigator.clipboard.writeText(trigger.webhookUrl);
      setNotice("Webhook URL copied. Paste it into GitLab under Settings → Webhooks.");
    } catch {
      setError("Could not copy. Select the URL and copy it by hand.");
    }
  }

  const nothingSelected = selectedTests.size === 0;

  return (
    <div className="stack">
      {error && (
        <p className="snackbar error" role="alert">
          <Icon name="warning" size={18} />
          {error}
        </p>
      )}

      {notice && (
        <p className="snackbar success" role="status">
          <Icon name="check" size={18} />
          {notice}
        </p>
      )}

      <section className="panel">
        <div className="panel-head">
          <div>
            <h2>Rules</h2>
            <p className="panel-sub">
              A push to the branch a rule watches starts that run by itself, and queues behind
              whatever the device is already doing.
            </p>
          </div>
        </div>

        {!loaded ? (
          <div className="stack" aria-busy="true">
            <span className="skeleton" style={{ height: 132, borderRadius: 16 }} />
            <span className="skeleton" style={{ height: 132, borderRadius: 16 }} />
          </div>
        ) : triggers.length === 0 ? (
          <div className="empty-state">
            <Icon name="bolt" size={40} />
            <h3 className="md-title">Nothing is automated yet</h3>
            <p className="md-body">
              Pick a repository below the same way you would to run a test by hand, and every push
              to the branch you choose will be tested on its own.
            </p>
          </div>
        ) : (
          <div className="stack">
            {triggers.map((trigger) => (
              <article className="card" key={trigger.id}>
                <div className="panel-head">
                  <div>
                    <h3 className="md-title">
                      {trigger.projectPath} <span className="muted">·</span> {trigger.branchPattern}
                    </h3>
                    <p className="md-body-sm muted">
                      {trigger.platform === "android" ? "Android device" : "Web"} ·{" "}
                      {trigger.orientation === "horizontal" ? "Landscape" : "Portrait"} ·{" "}
                      {trigger.tests.length === 0 ? "every flow" : trigger.tests.join(", ")}
                    </p>
                  </div>
                  <StatusChip
                    status={trigger.enabled ? "passed" : "skipped"}
                    label={trigger.enabled ? "listening" : "paused"}
                  />
                </div>

                <label className="field">
                  <span className="field-label">Webhook URL — paste this into GitLab</span>
                  <input
                    readOnly
                    value={trigger.webhookUrl}
                    onFocus={(event) => event.target.select()}
                  />
                  <span className="hint">
                    GitLab project → Settings → Webhooks → URL, with “Push events” on.
                  </span>
                </label>

                <div className="row">
                  <button type="button" className="md-button tonal" onClick={() => void copy(trigger)}>
                    <Icon name="external" size={18} />
                    Copy URL
                  </button>
                  <button
                    type="button"
                    className="md-button outlined"
                    onClick={() => void toggleRule(trigger)}
                  >
                    {trigger.enabled ? "Pause" : "Resume"}
                  </button>
                  <button
                    type="button"
                    className="md-button text"
                    onClick={() => void removeRule(trigger)}
                  >
                    Delete
                  </button>
                  <span className="md-body-sm muted" style={{ marginLeft: "auto" }}>
                    {trigger.fireCount === 0
                      ? "never triggered"
                      : `${trigger.fireCount} run${trigger.fireCount === 1 ? "" : "s"}${
                          trigger.lastFiredAt ? ` · last ${relativeTime(trigger.lastFiredAt)}` : ""
                        }${trigger.lastFiredBranch ? ` on ${trigger.lastFiredBranch}` : ""}`}
                  </span>
                </div>
              </article>
            ))}
          </div>
        )}
      </section>

      <section className="panel">
        <div className="panel-head">
          <div>
            <h2>New rule</h2>
            <p className="panel-sub">The same steps as starting a test by hand.</p>
          </div>
        </div>

        <div className="stack">
          <div className="field">
            <span className="field-label">1 · Which repository</span>
            <input
              placeholder="Filter repositories…"
              value={filter}
              onChange={(event) => setFilter(event.target.value)}
            />
            <div className="chip-row" role="listbox" aria-label="Repositories">
              {visible.map((item) => (
                <button
                  type="button"
                  key={item.id}
                  role="option"
                  aria-selected={project?.id === item.id}
                  className={project?.id === item.id ? "chip selected" : "chip"}
                  onClick={() => void chooseProject(item)}
                >
                  {item.pathWithNamespace}
                </button>
              ))}
              {visible.length === 0 && <span className="md-body-sm muted">No repository matches.</span>}
            </div>
          </div>

          {project && (
            <div className="field">
              <span className="field-label">2 · Which branch</span>
              <div className="chip-row" role="radiogroup" aria-label="Branches">
                {branches.slice(0, 40).map((item) => (
                  <button
                    type="button"
                    key={item.name}
                    className={branch === item.name ? "chip selected" : "chip"}
                    onClick={() => void chooseBranch(item.name)}
                  >
                    {item.name}
                    {item.default ? " ·default" : ""}
                  </button>
                ))}
                {branches.length === 0 && <span className="md-body-sm muted">Loading branches…</span>}
              </div>
            </div>
          )}

          {loading && <span className="skeleton" style={{ height: 44, borderRadius: 12 }} />}

          {check && !check.canContinue && (
            <p className="snackbar error" role="status">
              <Icon name="warning" size={18} />
              {!check.isFlutterApp ? check.isFlutterAppReason : check.hasMaestroFlowsReason} — this
              branch cannot be tested.
            </p>
          )}

          {check?.canContinue && tests && (
            <>
              <div className="field">
                <span className="field-label">3 · Which tests</span>
                <ul style={{ listStyle: "none", margin: 0, padding: 0 }} className="chip-row">
                  {tests.map((test) => (
                    <li key={test.name}>
                      <button
                        type="button"
                        aria-pressed={selectedTests.has(test.name)}
                        className={selectedTests.has(test.name) ? "chip selected" : "chip"}
                        onClick={() => toggleTest(test)}
                      >
                        {test.displayName || test.name}
                      </button>
                    </li>
                  ))}
                </ul>
                <span className="hint">
                  A whole-suite test covers every flow. Whatever you pick, <code>smoke</code> signs
                  in first on its own.
                </span>
              </div>

              <div className="split">
                <div className="field">
                  <span className="field-label">Where to run</span>
                  <div className="chip-row" role="radiogroup" aria-label="Where to run">
                    {LANES.map((item) => (
                      <button
                        type="button"
                        key={item.id}
                        className={platform === item.id ? "chip selected" : "chip"}
                        onClick={() => setPlatform(item.id)}
                      >
                        {item.label}
                      </button>
                    ))}
                  </div>
                </div>

                <div className="field">
                  <span className="field-label">Orientation</span>
                  <div className="chip-row" role="radiogroup" aria-label="Orientation">
                    {ORIENTATIONS.map((item) => (
                      <button
                        type="button"
                        key={item.id}
                        className={orientation === item.id ? "chip selected" : "chip"}
                        onClick={() => setOrientation(item.id)}
                      >
                        {item.label}
                      </button>
                    ))}
                  </div>
                </div>
              </div>

              <div className="row">
                <button
                  type="button"
                  className="md-button filled"
                  onClick={() => void addRule()}
                  disabled={saving || nothingSelected}
                >
                  {saving ? <span className="spinner" /> : <Icon name="bolt" size={18} />}
                  {saving ? "Adding…" : `Automate ${branch}`}
                </button>
                {nothingSelected && (
                  <span className="md-body-sm muted">Pick at least one test.</span>
                )}
              </div>
            </>
          )}
        </div>
      </section>
    </div>
  );
}
