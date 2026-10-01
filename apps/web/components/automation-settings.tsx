"use client";

import { useCallback, useEffect, useMemo, useState } from "react";

import { Icon } from "@/components/icons";
import { StatusChip, relativeTime } from "@/components/status";

type Trigger = {
  id: number;
  projectId: number;
  projectPath: string;
  branchPattern: string;
  tests: string[];
  runKinds: string[];
  environments: string[];
  orientation: string;
  platform: string;
  live: boolean;
  enabled: boolean;
  webhookUrl: string;
  lastFiredAt: string | null;
  lastFiredBranch: string | null;
  fireCount: number;
};

type Project = { id: number; path: string; name?: string };

const LANES = [
  { id: "android", label: "Android device" },
  { id: "web", label: "Web" },
];

const ORIENTATIONS = [
  { id: "vertical", label: "Portrait" },
  { id: "horizontal", label: "Landscape" },
];

/**
 * Pushes that should become runs. Each rule owns a webhook URL: paste it into the
 * GitLab project, choose the branch it listens to, and a push queues a run behind
 * whatever the device is already doing.
 */
export function AutomationSettings() {
  const [triggers, setTriggers] = useState<Trigger[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [projectId, setProjectId] = useState("");
  const [filter, setFilter] = useState("");
  const [branchPattern, setBranchPattern] = useState("");
  const [tests, setTests] = useState("");
  const [platform, setPlatform] = useState("android");
  const [orientation, setOrientation] = useState("vertical");

  const load = useCallback(async () => {
    const [rules, repos] = await Promise.all([
      fetch("/api/automation/triggers", { cache: "no-store" }),
      fetch("/api/gitlab/projects", { cache: "no-store" }),
    ]);

    if (rules.status === 401 || repos.status === 401) {
      window.location.href = "/login";
      return;
    }

    const rulesData = (await rules.json()) as { triggers?: Trigger[] };
    const reposData = (await repos.json()) as { projects?: Project[] };

    setTriggers(rulesData.triggers ?? []);
    setProjects(reposData.projects ?? []);
    setLoaded(true);
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const visible = useMemo(() => {
    const needle = filter.trim().toLowerCase();
    if (needle === "") return projects.slice(0, 60);

    return projects.filter((project) => (project.path ?? "").toLowerCase().includes(needle)).slice(0, 60);
  }, [projects, filter]);

  async function add(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      const project = projects.find((item) => String(item.id) === projectId);

      if (!project) {
        setError("Choose a repository first.");
        return;
      }

      const response = await fetch("/api/automation/triggers", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          projectId: project.id,
          projectPath: project.path,
          branchPattern,
          tests: tests
            .split(",")
            .map((item) => item.trim())
            .filter((item) => item !== ""),
          runKinds: ["maestro"],
          environments: ["development"],
          platform,
          orientation,
        }),
      });

      const data = (await response.json()) as { message?: string };

      if (!response.ok) {
        setError(data.message ?? "Could not create the rule.");
        return;
      }

      setBranchPattern("");
      setTests("");
      setProjectId("");
      setFilter("");
      setNotice("Rule added. Copy its webhook URL into GitLab.");
      await load();
    } finally {
      setBusy(false);
    }
  }

  async function toggle(trigger: Trigger) {
    setTriggers((current) =>
      current.map((item) => (item.id === trigger.id ? { ...item, enabled: !item.enabled } : item)),
    );

    await fetch(`/api/automation/triggers/${trigger.id}`, {
      method: "PATCH",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ enabled: !trigger.enabled }),
    });

    await load();
  }

  async function remove(trigger: Trigger) {
    await fetch(`/api/automation/triggers/${trigger.id}`, { method: "DELETE" });
    setNotice(`Removed the rule for ${trigger.projectPath}.`);
    await load();
  }

  async function copy(trigger: Trigger) {
    try {
      await navigator.clipboard.writeText(trigger.webhookUrl);
      setNotice("Webhook URL copied.");
    } catch {
      setError("Could not copy. Select the URL and copy it by hand.");
    }
  }

  return (
    <section className="panel">
      <div className="panel-head">
        <div>
          <h2>Automate test</h2>
          <p className="panel-sub">
            A push to a branch can start a test by itself. Each rule gets its own webhook URL for
            GitLab, and runs queue behind each other instead of competing for the device.
          </p>
        </div>
      </div>

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

      {loaded && triggers.length === 0 && (
        <div className="empty-state">
          <Icon name="bolt" size={40} />
          <h3 className="md-title">No rules yet</h3>
          <p className="md-body">
            Add one below, paste its URL into GitLab under Settings → Webhooks, and every push to
            the branch it names will be tested.
          </p>
        </div>
      )}

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
                  {trigger.tests.length === 0 ? "all flows" : trigger.tests.join(", ")}
                </p>
              </div>
              <StatusChip
                status={trigger.enabled ? "passed" : "skipped"}
                label={trigger.enabled ? "listening" : "paused"}
              />
            </div>

            <label className="field">
              <span className="field-label">Webhook URL — paste this into GitLab</span>
              <input readOnly value={trigger.webhookUrl} onFocus={(event) => event.target.select()} />
              <span className="hint">
                GitLab project → Settings → Webhooks → URL. Keep “Push events” on.
              </span>
            </label>

            <div className="row">
              <button type="button" className="md-button tonal" onClick={() => void copy(trigger)}>
                <Icon name="external" size={18} />
                Copy URL
              </button>
              <button type="button" className="md-button outlined" onClick={() => void toggle(trigger)}>
                {trigger.enabled ? "Pause" : "Resume"}
              </button>
              <button type="button" className="md-button text" onClick={() => void remove(trigger)}>
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

      <form className="stack" onSubmit={add}>
        <h3 className="md-title">Add a rule</h3>

        <div className="split">
          <label className="field">
            <span className="field-label">Repository</span>
            <input
              placeholder="filter…"
              value={filter}
              onChange={(event) => setFilter(event.target.value)}
            />
            <select
              value={projectId}
              onChange={(event) => setProjectId(event.target.value)}
              required
            >
              <option value="">Choose a repository…</option>
              {visible.map((project) => (
                <option value={project.id} key={project.id}>
                  {project.path}
                </option>
              ))}
            </select>
          </label>

          <label className="field">
            <span className="field-label">Branch</span>
            <input
              placeholder="main, release/*, *"
              value={branchPattern}
              onChange={(event) => setBranchPattern(event.target.value)}
              required
            />
            <span className="hint">A glob: <code>main</code>, <code>release/*</code> or <code>*</code>.</span>
          </label>
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

        <label className="field">
          <span className="field-label">Flows</span>
          <input
            placeholder="leave blank for every flow"
            value={tests}
            onChange={(event) => setTests(event.target.value)}
          />
          <span className="hint">Comma separated Maestro flow names, or blank to run them all.</span>
        </label>

        <div className="row">
          <button type="submit" className="md-button filled" disabled={busy}>
            {busy ? <span className="spinner" /> : <Icon name="bolt" size={18} />}
            {busy ? "Adding…" : "Add rule"}
          </button>
        </div>
      </form>
    </section>
  );
}
