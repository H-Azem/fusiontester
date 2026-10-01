"use client";

import { useCallback, useEffect, useMemo, useState } from "react";

import { Bird } from "./bird";
import { BranchCheckPanel } from "./branch-check-panel";
import { Icon } from "./icons";

type Project = {
  id: number;
  name: string;
  pathWithNamespace: string;
  defaultBranch: string | null;
  visibility: string;
  lastActivityAt: string;
  webUrl: string;
  pinned: boolean;
};

type Branch = {
  name: string;
  default: boolean;
  protected: boolean;
  lastCommitAt: string | null;
  pinned: boolean;
};

type PinKind = "repository" | "branch";

const repoKey = (projectId: number) => `repository:${projectId}`;
const branchKey = (projectId: number, branch: string) => `branch:${projectId}:${branch}`;

/** Stable sort: pinned entries first, original order preserved within groups. */
function pinnedFirst<T>(items: T[], isPinned: (item: T) => boolean): T[] {
  return [...items].sort((a, b) => Number(isPinned(b)) - Number(isPinned(a)));
}

export function TestApps() {
  const [projects, setProjects] = useState<Project[] | null>(null);
  const [branches, setBranches] = useState<Record<number, Branch[]>>({});
  const [pins, setPins] = useState<Set<string>>(new Set());
  const [truncated, setTruncated] = useState(false);
  const [filter, setFilter] = useState("");
  const [expanded, setExpanded] = useState<number | null>(null);
  const [selectedBranch, setSelectedBranch] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [branchError, setBranchError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [loadingBranches, setLoadingBranches] = useState<number | null>(null);

  const loadPins = useCallback(async () => {
    const response = await fetch("/api/pins", { cache: "no-store" });
    if (!response.ok) return;

    const data = (await response.json()) as {
      pins: Array<{ kind: PinKind; projectId: number; branch: string }>;
    };

    setPins(
      new Set(
        data.pins.map((pin) =>
          pin.kind === "repository" ? repoKey(pin.projectId) : branchKey(pin.projectId, pin.branch),
        ),
      ),
    );
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setBranches({});
    setExpanded(null);
    setSelectedBranch(null);

    try {
      const [projectsResponse] = await Promise.all([
        fetch("/api/gitlab/projects", { cache: "no-store" }),
        loadPins(),
      ]);

      const data = (await projectsResponse.json()) as {
        projects?: Project[];
        truncated?: boolean;
        message?: string;
      };

      if (!projectsResponse.ok) {
        setProjects(null);
        setError(data.message ?? "Could not load repositories.");
        return;
      }

      setProjects(data.projects ?? []);
      setTruncated(Boolean(data.truncated));
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setLoading(false);
    }
  }, [loadPins]);

  // The repository list is the whole point of this screen, so it loads on arrival.
  useEffect(() => {
    void load();
  }, [load]);

  async function loadBranches(projectId: number) {
    setBranchError(null);
    setLoadingBranches(projectId);

    try {
      const response = await fetch(`/api/gitlab/projects/${projectId}/branches`, {
        cache: "no-store",
      });
      const data = (await response.json()) as { branches?: Branch[]; message?: string };

      if (!response.ok) {
        setBranchError(data.message ?? "Could not load branches.");
        return;
      }

      setBranches((current) => ({ ...current, [projectId]: data.branches ?? [] }));
    } catch (err) {
      setBranchError(err instanceof Error ? err.message : String(err));
    } finally {
      setLoadingBranches(null);
    }
  }

  async function togglePin(kind: PinKind, projectId: number, projectPath: string, branch?: string) {
    const key = kind === "repository" ? repoKey(projectId) : branchKey(projectId, branch ?? "");
    const wasPinned = pins.has(key);

    // Optimistic so the row jumps immediately.
    setPins((current) => {
      const next = new Set(current);
      if (wasPinned) next.delete(key);
      else next.add(key);
      return next;
    });

    try {
      const response = wasPinned
        ? await fetch(
            `/api/pins?kind=${kind}&projectId=${projectId}` +
              (branch ? `&branch=${encodeURIComponent(branch)}` : ""),
            { method: "DELETE" },
          )
        : await fetch("/api/pins", {
            method: "PUT",
            headers: { "content-type": "application/json" },
            body: JSON.stringify({ kind, projectId, projectPath, branch }),
          });

      if (!response.ok) throw new Error("Pin update was rejected");
    } catch (err) {
      setPins((current) => {
        const next = new Set(current);
        if (wasPinned) next.add(key);
        else next.delete(key);
        return next;
      });
      setError(err instanceof Error ? err.message : String(err));
    }
  }

  async function toggleExpanded(projectId: number) {
    if (expanded === projectId) {
      setExpanded(null);
      return;
    }

    setExpanded(projectId);
    setSelectedBranch(null);

    if (!branches[projectId]) await loadBranches(projectId);
  }

  const visibleProjects = useMemo(() => {
    if (!projects) return [];
    const needle = filter.trim().toLowerCase();
    const matching =
      needle === ""
        ? projects
        : projects.filter((project) => project.pathWithNamespace.toLowerCase().includes(needle));

    return pinnedFirst(matching, (project) => pins.has(repoKey(project.id)));
  }, [projects, filter, pins]);

  const pinnedRepoCount = useMemo(
    () => (projects ?? []).filter((project) => pins.has(repoKey(project.id))).length,
    [projects, pins],
  );

  return (
    <>
      <div className="toolbar">
        <label className="search-field">
          <span className="search-icon">
            <Icon name="search" size={18} />
          </span>
          <input
            type="search"
            placeholder="Filter repositories by path"
            value={filter}
            onChange={(event) => setFilter(event.target.value)}
            aria-label="Filter repositories"
          />
        </label>
        <button
          type="button"
          className="md-button tonal"
          onClick={() => void load()}
          disabled={loading}
        >
          {loading ? <span className="spinner" /> : <Icon name="refresh" size={18} />}
          Refresh
        </button>
        {projects && !loading && (
          <span className="md-body-sm faint">
            {projects.length} repositor{projects.length === 1 ? "y" : "ies"}
            {pinnedRepoCount > 0 ? ` · ${pinnedRepoCount} pinned` : ""}
          </span>
        )}
      </div>

      {error && (
        <p className="snackbar error" role="alert">
          <Icon name="warning" size={18} />
          {error}
        </p>
      )}

      {!projects && loading && (
        <div className="stack" aria-busy="true">
          <span className="skeleton" style={{ height: 64 }} />
          <span className="skeleton" style={{ height: 64 }} />
          <span className="skeleton" style={{ height: 64 }} />
        </div>
      )}

      {projects && visibleProjects.length === 0 && (
        <div className="empty-state">
          <Bird state="not-found" size={104} />
          <h3 className="md-title">Nothing matches that filter</h3>
          <p className="md-body">
            {projects.length === 0
              ? "The GitLab connection returned no repositories. Check the token in Settings."
              : "No repository path contains that text. Clear the search to see them all."}
          </p>
          {filter !== "" && (
            <button type="button" className="md-button tonal" onClick={() => setFilter("")}>
              Clear the search
            </button>
          )}
        </div>
      )}

      {visibleProjects.length > 0 && (
        <ul className="list" style={{ listStyle: "none", margin: 0, padding: 0 }}>
          {visibleProjects.map((project) => {
            const isPinned = pins.has(repoKey(project.id));
            const isExpanded = expanded === project.id;
            const projectBranches = branches[project.id];

            return (
              <li key={project.id} className="card">
                <div className="row">
                  <button
                    type="button"
                    className={isPinned ? "md-button icon" : "md-button icon faint"}
                    style={isPinned ? { color: "var(--warning)" } : undefined}
                    title={isPinned ? "Unpin repository" : "Pin repository"}
                    aria-label={isPinned ? "Unpin repository" : "Pin repository"}
                    aria-pressed={isPinned}
                    onClick={() => void togglePin("repository", project.id, project.pathWithNamespace)}
                  >
                    <Icon name="star" size={18} />
                  </button>

                  <span className="list-item-main">
                    <a className="repo-path" href={project.webUrl} target="_blank" rel="noreferrer">
                      {project.pathWithNamespace}
                    </a>
                    <span className="list-item-sub">
                      {project.visibility} · {project.defaultBranch ?? "no default branch"} · last
                      activity {new Date(project.lastActivityAt).toLocaleDateString()}
                    </span>
                  </span>

                  <button
                    type="button"
                    className="md-button tonal small"
                    onClick={() => void toggleExpanded(project.id)}
                    aria-expanded={isExpanded}
                  >
                    <Icon name={isExpanded ? "chevronDown" : "branch"} size={16} />
                    {isExpanded ? "Hide branches" : "Branches"}
                  </button>
                </div>

                {isExpanded && (
                  <div className="branches stack">
                    {loadingBranches === project.id && (
                      <div className="stack" aria-busy="true">
                        <span className="skeleton" style={{ width: "45%" }} />
                        <span className="skeleton" style={{ width: "35%" }} />
                      </div>
                    )}

                    {branchError && (
                      <p className="snackbar error" role="alert">
                        <Icon name="warning" size={18} />
                        {branchError}
                      </p>
                    )}

                    {projectBranches && projectBranches.length === 0 && (
                      <p className="md-body-sm muted">This repository has no branches.</p>
                    )}

                    {projectBranches && projectBranches.length > 0 && (
                      <>
                        <p className="md-body-sm faint">Pick the branch to test</p>
                        <ul className="chip-row" style={{ listStyle: "none", margin: 0, padding: 0 }}>
                          {pinnedFirst(projectBranches, (branch) =>
                            pins.has(branchKey(project.id, branch.name)),
                          ).map((branch) => {
                            const branchPinned = pins.has(branchKey(project.id, branch.name));
                            const selected = selectedBranch === branch.name;

                            return (
                              <li key={branch.name} className="row" style={{ gap: 4 }}>
                                <button
                                  type="button"
                                  className={selected ? "chip selected" : "chip"}
                                  aria-pressed={selected}
                                  onClick={() => setSelectedBranch(branch.name)}
                                >
                                  {branch.default && <Icon name="star" size={13} />}
                                  {branch.name}
                                  {branch.protected && <span className="faint">· protected</span>}
                                </button>
                                <button
                                  type="button"
                                  className="md-button icon"
                                  style={branchPinned ? { color: "var(--warning)" } : undefined}
                                  aria-label={branchPinned ? "Unpin branch" : "Pin branch"}
                                  aria-pressed={branchPinned}
                                  onClick={() =>
                                    void togglePin(
                                      "branch",
                                      project.id,
                                      project.pathWithNamespace,
                                      branch.name,
                                    )
                                  }
                                >
                                  <Icon name="star" size={15} />
                                </button>
                              </li>
                            );
                          })}
                        </ul>

                        {selectedBranch && (
                          <BranchCheckPanel
                            key={`${project.id}:${selectedBranch}`}
                            projectId={project.id}
                            projectPath={project.pathWithNamespace}
                            branch={selectedBranch}
                          />
                        )}
                      </>
                    )}
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}

      {truncated && (
        <p className="md-body-sm muted">Showing the most recently active projects only.</p>
      )}
    </>
  );
}
