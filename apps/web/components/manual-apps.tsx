"use client";

import { useCallback, useEffect, useMemo, useState } from "react";

import { Icon } from "@/components/icons";
import { StatusChip, relativeTime } from "@/components/status";

type Project = { id: number; name: string; pathWithNamespace: string; defaultBranch: string };
type Branch = { name: string; default: boolean };
type BranchCheck = { ref: string; isFlutterApp: boolean; isFlutterAppReason: string };
type ManualApp = {
  id: number;
  projectId: number;
  projectPath: string;
  branch: string;
  title: string;
  apkBuiltAt?: string | null;
  buildError?: string | null;
};

/**
 * Apps built for testing by hand. This step is the register: what to call the app,
 * which repository and which branch. The hourly build is a later step.
 */
export function ManualApps() {
  const [apps, setApps] = useState<ManualApp[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [branches, setBranches] = useState<Branch[]>([]);
  const [check, setCheck] = useState<BranchCheck | null>(null);
  const [filter, setFilter] = useState("");
  const [project, setProject] = useState<Project | null>(null);
  const [branch, setBranch] = useState("");
  const [title, setTitle] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [buildingId, setBuildingId] = useState<number | null>(null);

  const load = useCallback(async () => {
    const response = await fetch("/api/settings/manual-apps", { cache: "no-store" });
    if (response.status === 401) {
      window.location.href = "/login";
      return;
    }

    const data = (await response.json()) as { apps?: ManualApp[] };
    setApps(data.apps ?? []);
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    void (async () => {
      const response = await fetch("/api/gitlab/projects", { cache: "no-store" });
      if (!response.ok) return;
      const data = (await response.json()) as { projects?: Project[] };
      setProjects(data.projects ?? []);
    })();
  }, []);

  const visibleProjects = useMemo(() => {
    const needle = filter.trim().toLowerCase();

    return projects
      .filter((item) => needle === "" || item.pathWithNamespace.toLowerCase().includes(needle))
      .slice(0, 40);
  }, [projects, filter]);

  async function checkBranch(target: Project, ref: string) {
    setBranch(ref);

    const response = await fetch(
      `/api/gitlab/projects/${target.id}/branch-check?ref=${encodeURIComponent(ref)}`,
      { cache: "no-store" },
    );

    setCheck(response.ok ? ((await response.json()) as BranchCheck) : null);
  }

  async function chooseProject(next: Project) {
    setProject(next);
    setCheck(null);
    setBranches([]);

    const response = await fetch(`/api/gitlab/projects/${next.id}/branches`, { cache: "no-store" });
    if (response.ok) {
      const data = (await response.json()) as { branches?: Branch[] };
      setBranches(data.branches ?? []);
    }

    await checkBranch(next, next.defaultBranch);
  }

  async function add() {
    if (project === null || branch === "" || title.trim() === "") return;

    setSaving(true);
    setError(null);
    setNotice(null);

    try {
      const response = await fetch("/api/settings/manual-apps", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          projectId: project.id,
          projectPath: project.pathWithNamespace,
          branch,
          title: title.trim(),
        }),
      });

      const data = (await response.json()) as { message?: string };
      if (!response.ok) {
        setError(data.message ?? "Could not add the app.");
        return;
      }

      setTitle("");
      setNotice("Added — it will be built for the device.");
      await load();
    } finally {
      setSaving(false);
    }
  }

  async function buildNow(app: ManualApp) {
    setBuildingId(app.id);

    try {
      await fetch(`/api/settings/manual-apps/${app.id}/build`, { method: "POST" });
      setNotice(`Building ${app.title}… its build time will update when it lands.`);
      await load();
    } finally {
      setBuildingId(null);
    }
  }

  async function remove(app: ManualApp) {
    await fetch(`/api/settings/manual-apps/${app.id}`, { method: "DELETE" });
    await load();
  }

  const canAdd = project !== null && branch !== "" && title.trim() !== "" && check?.isFlutterApp === true;

  return (
    <section className="panel">
      <div className="panel-head">
        <div>
          <h2>Manual test apps</h2>
          <p className="panel-sub">
            Builds an app&apos;s APK so it can be installed on the device by hand.
          </p>
        </div>
      </div>

      <div className="stack">
        <label className="field">
          <span className="field-label">1 · Title</span>
          <input
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            placeholder="POS — develop"
            maxLength={120}
          />
          <span className="hint">What this app is called in the list.</span>
        </label>

        <div className="stack">
          <span className="field-label">2 · Repository</span>
          <input
            value={filter}
            onChange={(event) => setFilter(event.target.value)}
            placeholder="Filter repositories…"
            spellCheck={false}
          />
          <div className="chip-row" role="listbox" aria-label="Repositories">
            {visibleProjects.map((item) => (
              <button
                key={item.id}
                type="button"
                className={project?.id === item.id ? "chip selected" : "chip"}
                onClick={() => void chooseProject(item)}
              >
                {item.pathWithNamespace}
              </button>
            ))}
          </div>
        </div>

        {project && (
          <div className="stack">
            <span className="field-label">3 · Branch</span>
            <div className="chip-row" role="radiogroup" aria-label="Branches">
              {branches.length === 0 ? (
                <span className="md-body-sm muted">Loading branches…</span>
              ) : (
                branches.map((item) => (
                  <button
                    key={item.name}
                    type="button"
                    className={branch === item.name ? "chip selected" : "chip"}
                    onClick={() => void checkBranch(project, item.name)}
                  >
                    {item.name}
                    {item.default ? " ·default" : ""}
                  </button>
                ))
              )}
            </div>
          </div>
        )}

        {check && (
          <p className="md-body-sm">
            {check.isFlutterApp ? (
              <span className="check ok">
                <span className="mark">✓</span> Flutter app
              </span>
            ) : (
              <span className="check fail">
                <span className="mark">✗</span> {check.isFlutterAppReason}
              </span>
            )}
          </p>
        )}

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

        <div className="row">
          <button type="button" className="md-button filled" onClick={() => void add()} disabled={!canAdd || saving}>
            {saving ? <span className="spinner" /> : <Icon name="add" size={18} />}
            {saving ? "Adding…" : "Add app"}
          </button>
          {!canAdd && (
            <span className="md-body-sm muted">
              A title, a repository and a branch that is a Flutter app.
            </span>
          )}
        </div>
      </div>

      <div className="stack" style={{ marginTop: 16 }}>
        {apps.length === 0 ? (
          <p className="md-body-sm muted">No apps yet.</p>
        ) : (
          apps.map((app) => (
            <article key={app.id} className="card">
              <div className="panel-head">
                <div>
                  <h3 className="md-title">{app.title}</h3>
                  <p className="md-body-sm muted">
                    {app.projectPath} · <span className="mono">{app.branch}</span>
                  </p>
                </div>
                <StatusChip
                  status={app.buildError ? "failed" : app.apkBuiltAt ? "passed" : "queued"}
                  label={app.buildError ? "build failed" : app.apkBuiltAt ? "built" : "no build yet"}
                />
              </div>
              <p className="md-body-sm muted">
                {app.apkBuiltAt ? `Built ${relativeTime(app.apkBuiltAt)}` : "Not built yet."}
              </p>
              <div className="row">
                <button
                  type="button"
                  className="md-button tonal"
                  onClick={() => void buildNow(app)}
                  disabled={buildingId === app.id}
                >
                  {buildingId === app.id ? <span className="spinner" /> : <Icon name="bolt" size={18} />}
                  {buildingId === app.id ? "Building…" : "Build now"}
                </button>
                <button type="button" className="md-button text" onClick={() => void remove(app)}>
                  Delete
                </button>
              </div>
              {app.buildError && <pre className="output error">{app.buildError}</pre>}
            </article>
          ))
        )}
      </div>
    </section>
  );
}
