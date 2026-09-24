"use client";

import { useCallback, useEffect, useMemo, useState } from "react";

import { BranchCheckPanel } from "./branch-check-panel";

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

  // The repository list is the whole point of this screen, so it loads on
  // arrival instead of waiting to be asked for.
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

  async function togglePin(
    kind: PinKind,
    projectId: number,
    projectPath: string,
    branch?: string,
  ) {
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
      // Roll back on failure.
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

    if (!branches[projectId]) {
      await loadBranches(projectId);
    }
  }

  const visibleProjects = useMemo(() => {
    if (!projects) return [];
    const needle = filter.trim().toLowerCase();
    const matching =
      needle === ""
        ? projects
        : projects.filter((project) =>
            project.pathWithNamespace.toLowerCase().includes(needle),
          );

    return pinnedFirst(matching, (project) => pins.has(repoKey(project.id)));
  }, [projects, filter, pins]);

  const pinnedRepoCount = useMemo(
    () => (projects ?? []).filter((project) => pins.has(repoKey(project.id))).length,
    [projects, pins],
  );

  return (
    <>
      <div className="toolbar">
        <button type="button" onClick={() => void load()} disabled={loading}>
          {loading ? "Refreshing…" : "Refresh"}
        </button>
        {projects && !loading && (
          <span className="muted">
            {projects.length} project{projects.length === 1 ? "" : "s"}
            {pinnedRepoCount > 0 ? ` · ${pinnedRepoCount} pinned` : ""}
          </span>
        )}
      </div>

      {error && <p className="error">{error}</p>}

      {!projects && loading && <p className="muted">Loading repositories…</p>}

      {projects && (
        <>
          <input
            className="repos-filter"
            placeholder="Filter by path…"
            value={filter}
            onChange={(event) => setFilter(event.target.value)}
          />

          {visibleProjects.length === 0 ? (
            <p className="muted">No projects match that filter.</p>
          ) : (
            <ul className="repos">
              {visibleProjects.map((project) => {
                const isPinned = pins.has(repoKey(project.id));
                const isExpanded = expanded === project.id;
                const projectBranches = branches[project.id];

                return (
                  <li key={project.id}>
                    <div className="repo-row">
                      <button
                        type="button"
                        className={isPinned ? "pin pinned" : "pin"}
                        title={isPinned ? "Unpin repository" : "Pin repository"}
                        aria-label={isPinned ? "Unpin repository" : "Pin repository"}
                        onClick={() =>
                          void togglePin("repository", project.id, project.pathWithNamespace)
                        }
                      >
                        {isPinned ? "★" : "☆"}
                      </button>

                      <a
                        className="repo-path"
                        href={project.webUrl}
                        target="_blank"
                        rel="noreferrer"
                      >
                        {project.pathWithNamespace}
                      </a>

                      <span className="muted repo-meta">
                        {project.visibility} · {project.defaultBranch ?? "no default branch"} ·{" "}
                        {new Date(project.lastActivityAt).toLocaleDateString()}
                      </span>

                      <button
                        type="button"
                        className="link-button"
                        onClick={() => void toggleExpanded(project.id)}
                      >
                        {isExpanded ? "Hide branches" : "Branches"}
                      </button>
                    </div>

                    {isExpanded && (
                      <div className="branches">
                        {loadingBranches === project.id && (
                          <p className="muted">Loading branches…</p>
                        )}
                        {branchError && <p className="error">{branchError}</p>}

                        {projectBranches && projectBranches.length === 0 && (
                          <p className="muted">This repository has no branches.</p>
                        )}

                        {projectBranches && projectBranches.length > 0 && (
                          <ul>
                            {pinnedFirst(projectBranches, (branch) =>
                              pins.has(branchKey(project.id, branch.name)),
                            ).map((branch) => {
                              const branchPinned = pins.has(branchKey(project.id, branch.name));

                              return (
                                <li key={branch.name}>
                                  <button
                                    type="button"
                                    className={branchPinned ? "pin pinned" : "pin"}
                                    title={branchPinned ? "Unpin branch" : "Pin branch"}
                                    aria-label={branchPinned ? "Unpin branch" : "Pin branch"}
                                    onClick={() =>
                                      void togglePin(
                                        "branch",
                                        project.id,
                                        project.pathWithNamespace,
                                        branch.name,
                                      )
                                    }
                                  >
                                    {branchPinned ? "★" : "☆"}
                                  </button>
                                  <button
                                    type="button"
                                    className={
                                      selectedBranch === branch.name
                                        ? "branch-select selected"
                                        : "branch-select"
                                    }
                                    aria-pressed={selectedBranch === branch.name}
                                    onClick={() => setSelectedBranch(branch.name)}
                                  >
                                    {branch.name}
                                  </button>
                                  {branch.default && <span className="tag">default</span>}
                                  {branch.protected && <span className="tag">protected</span>}
                                  {branch.lastCommitAt && (
                                    <span className="muted branch-date">
                                      {new Date(branch.lastCommitAt).toLocaleDateString()}
                                    </span>
                                  )}
                                </li>
                              );
                            })}
                          </ul>
                        )}

                        {selectedBranch && (
                          <BranchCheckPanel
                            key={`${project.id}:${selectedBranch}`}
                            projectId={project.id}
                            projectPath={project.pathWithNamespace}
                            branch={selectedBranch}
                          />
                        )}
                      </div>
                    )}
                  </li>
                );
              })}
            </ul>
          )}

          {truncated && (
            <p className="muted">Showing the most recently active projects only.</p>
          )}
        </>
      )}
    </>
  );
}
