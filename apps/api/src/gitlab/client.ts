import { execFile } from "node:child_process";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { rootCertificates } from "node:tls";
import { promisify } from "node:util";
import { Agent, request } from "undici";

import { decryptSecret } from "../crypto/secret-box.js";
import { db } from "../db/index.js";
import { gitlabConnections } from "../db/schema.js";

const execFileAsync = promisify(execFile);

/** Scopes Fusion Tester needs: API reads plus repository contents (API and clone). */
export const REQUIRED_SCOPES = ["read_api", "read_repository"];

export type GitlabConnection = {
  baseUrl: string;
  token: string;
  caCertificate: string | null;
};

export type SelfToken = {
  id?: number;
  name?: string;
  scopes?: string[];
  expires_at?: string | null;
  user_id?: number;
};

export type GitlabUser = {
  id: number;
  username: string;
  name: string;
};

export type CloneCheck = {
  attempted: boolean;
  ok: boolean;
  repo: string | null;
  message: string | null;
};

export type VerifyResult = {
  ok: boolean;
  baseUrl: string;
  identity: GitlabUser | null;
  tokenName: string | null;
  scopes: string[] | null;
  scopesReadable: boolean;
  expiresAt: string | null;
  daysUntilExpiry: number | null;
  missingScopes: string[];
  warnings: string[];
  clone: CloneCheck;
  error: string | null;
};

/** Strips a token out of anything we are about to log, store or return. */
export function redact(text: string, token: string): string {
  if (!token) return text;
  return text.split(token).join("[redacted]");
}

/** Adds a scheme when missing and removes trailing slashes. */
export function normalizeBaseUrl(input: string): string {
  const trimmed = input.trim().replace(/\/+$/, "");
  if (trimmed === "") return trimmed;
  return /^https?:\/\//i.test(trimmed) ? trimmed : `https://${trimmed}`;
}

export async function getStoredConnection(): Promise<GitlabConnection | null> {
  const rows = await db.select().from(gitlabConnections).limit(1);
  const row = rows[0];
  if (!row) return null;

  return {
    baseUrl: row.baseUrl,
    token: decryptSecret(row.tokenCiphertext),
    caCertificate: row.caCertificate,
  };
}

/**
 * A custom CA extends Node's bundled roots rather than replacing them —
 * otherwise supplying an internal CA would break access to public hosts.
 */
const agents = new Map<string, Agent>();

function dispatcherFor(caCertificate: string | null): Agent {
  const cacheKey = caCertificate ?? "";
  const cached = agents.get(cacheKey);
  if (cached) return cached;

  const ca = caCertificate
    ? `${rootCertificates.join("\n")}\n${caCertificate}`
    : undefined;

  const agent = new Agent({ connect: ca ? { ca } : {} });
  agents.set(cacheKey, agent);
  return agent;
}

type GitlabResponse<T> = {
  status: number;
  ok: boolean;
  data: T | null;
  raw: string;
  headers: Record<string, string | string[] | undefined>;
};

async function gitlabRequest<T>(
  connection: GitlabConnection,
  path: string,
  init: { method?: string; body?: string } = {},
): Promise<GitlabResponse<T>> {
  const url = `${connection.baseUrl}/api/v4${path}`;

  const response = await request(url, {
    method: (init.method ?? "GET") as "GET" | "POST",
    headers: {
      "private-token": connection.token,
      accept: "application/json",
      ...(init.body ? { "content-type": "application/json" } : {}),
    },
    body: init.body,
    dispatcher: dispatcherFor(connection.caCertificate),
    headersTimeout: 15_000,
    bodyTimeout: 15_000,
  });

  const raw = await response.body.text();
  let data: T | null = null;
  try {
    data = JSON.parse(raw) as T;
  } catch {
    data = null;
  }

  return {
    status: response.statusCode,
    ok: response.statusCode >= 200 && response.statusCode < 300,
    data,
    raw,
    headers: response.headers as Record<string, string | string[] | undefined>,
  };
}

function describeHttpError(connection: GitlabConnection, raw: string, status: number): string {
  const safe = redact(raw.slice(0, 500), connection.token);
  if (status === 401) return "Authentication failed (401). The token is invalid, revoked, or expired.";
  if (status === 403) {
    return `Permission denied (403). The token is probably missing a required scope — listing projects needs read_api. ${safe}`;
  }
  if (status === 404) return "Not found (404). Check the base URL and that the API is reachable.";
  return `GitLab responded with ${status}. ${safe}`;
}

/**
 * Proves `read_repository` actually works by running `git ls-remote`, which is
 * the only check that exercises the clone path. A scope listing alone can look
 * correct while cloning still fails.
 */
async function verifyClone(
  connection: GitlabConnection,
  repoPath: string,
): Promise<CloneCheck> {
  const cleanRepo = repoPath.trim().replace(/^\/+/, "").replace(/\.git$/, "");
  if (cleanRepo === "") {
    return { attempted: false, ok: false, repo: null, message: null };
  }

  const parsed = new URL(connection.baseUrl);
  const basePath = parsed.pathname.replace(/\/+$/, "");
  const cloneUrl = `${parsed.protocol}//oauth2:${encodeURIComponent(connection.token)}@${parsed.host}${basePath}/${cleanRepo}.git`;

  const args: string[] = [];
  let caDir: string | null = null;

  if (connection.caCertificate) {
    caDir = await mkdtemp(join(tmpdir(), "ft-git-ca-"));
    const caPath = join(caDir, "ca.pem");
    await writeFile(caPath, connection.caCertificate, "utf8");
    args.push("-c", `http.sslCAInfo=${caPath}`);
  }

  args.push("ls-remote", "--heads", cloneUrl);

  try {
    const { stdout } = await execFileAsync("git", args, {
      // Never let git block waiting for credentials on a headless server.
      env: { ...process.env, GIT_TERMINAL_PROMPT: "0", GIT_ASKPASS: "" },
      timeout: 25_000,
      windowsHide: true,
    });

    const branches = stdout.split("\n").filter((line) => line.trim() !== "").length;
    return {
      attempted: true,
      ok: true,
      repo: cleanRepo,
      message: `Cloned metadata successfully — ${branches} branch${branches === 1 ? "" : "es"} visible.`,
    };
  } catch (error) {
    const raw =
      typeof error === "object" && error !== null && "stderr" in error
        ? String((error as { stderr?: unknown }).stderr ?? "")
        : "";
    const fallback = error instanceof Error ? error.message : String(error);
    const detail = redact((raw.trim() || fallback).slice(0, 800), connection.token);

    return {
      attempted: true,
      ok: false,
      repo: cleanRepo,
      message: detail,
    };
  } finally {
    if (caDir) await rm(caDir, { recursive: true, force: true });
  }
}

export async function verifyConnection(
  connection: GitlabConnection,
  testRepo?: string,
): Promise<VerifyResult> {
  const result: VerifyResult = {
    ok: false,
    baseUrl: connection.baseUrl,
    identity: null,
    tokenName: null,
    scopes: null,
    scopesReadable: false,
    expiresAt: null,
    daysUntilExpiry: null,
    missingScopes: [],
    warnings: [],
    clone: { attempted: false, ok: false, repo: null, message: null },
    error: null,
  };

  let self;
  try {
    self = await gitlabRequest<SelfToken>(connection, "/personal_access_tokens/self");
  } catch (error) {
    result.error = redact(
      error instanceof Error ? error.message : String(error),
      connection.token,
    );
    return result;
  }

  if (self.status === 401) {
    // Recorded, but the clone check still runs so the user learns whether the
    // host and repository are reachable independently of the token.
    result.error = describeHttpError(connection, self.raw, self.status);
  } else if (self.ok && self.data) {
    result.scopesReadable = true;
    result.scopes = Array.isArray(self.data.scopes) ? self.data.scopes : [];
    result.tokenName = self.data.name ?? null;
    result.expiresAt = self.data.expires_at ?? null;

    if (result.expiresAt) {
      const expires = new Date(result.expiresAt).getTime();
      if (Number.isFinite(expires)) {
        result.daysUntilExpiry = Math.floor((expires - Date.now()) / 86_400_000);
        if (result.daysUntilExpiry < 0) {
          result.warnings.push("This token has already expired.");
        } else if (result.daysUntilExpiry <= 14) {
          result.warnings.push(`This token expires in ${result.daysUntilExpiry} day(s).`);
        }
      }
    }

    result.missingScopes = REQUIRED_SCOPES.filter(
      (scope) => !(result.scopes ?? []).includes(scope),
    );
  } else {
    // Older GitLab versions and group/project tokens cannot introspect themselves.
    result.warnings.push(
      "Could not read this token's scopes. Group and project access tokens, and older GitLab versions, do not support /personal_access_tokens/self — verify scopes manually as read_api + read_repository.",
    );
  }

  try {
    const user = await gitlabRequest<GitlabUser>(connection, "/user");
    if (user.ok && user.data) {
      result.identity = user.data;
    } else if (user.status === 403) {
      result.warnings.push(
        "Could not read the token's user identity — this needs read_api or read_user.",
      );
    }
  } catch {
    result.warnings.push("Could not reach /user to confirm the token identity.");
  }

  if (testRepo && testRepo.trim() !== "") {
    result.clone = await verifyClone(connection, testRepo);

    // Public projects clone without credentials, so a green clone check on its
    // own must not be read as confirmation that the token is valid.
    if (result.clone.ok && result.identity === null) {
      result.warnings.push(
        "The repository was reachable, but public projects clone anonymously — this does not confirm the token works. It only proves a private repository would be reachable with a valid token.",
      );
    }
  }

  result.ok =
    result.error === null &&
    result.identity !== null &&
    result.missingScopes.length === 0 &&
    (!result.clone.attempted || result.clone.ok);

  return result;
}

export type GitlabProject = {
  id: number;
  name: string;
  path_with_namespace: string;
  default_branch: string | null;
  visibility: string;
  last_activity_at: string;
  web_url: string;
};

export class GitlabApiError extends Error {
  readonly status: number;

  constructor(message: string, status: number) {
    super(message);
    this.name = "GitlabApiError";
    this.status = status;
  }
}

const PAGE_SIZE = 100;
// Bounds the work and payload for very large instances; `truncated` says so.
const MAX_PAGES = 3;
// Pinned projects outside the fetched window are looked up individually.
const MAX_PINNED_LOOKUPS = 20;

/**
 * Lists projects the token can see, newest activity first.
 *
 * `simple=true` is deliberately not sent: it returns a reduced representation
 * that omits `name` and `path_with_namespace`.
 */
export async function listProjects(
  connection: GitlabConnection,
  options: { search?: string } = {},
): Promise<{ projects: GitlabProject[]; truncated: boolean }> {
  const projects: GitlabProject[] = [];
  let page: string | null = "1";
  let pagesFetched = 0;

  while (page !== null && pagesFetched < MAX_PAGES) {
    const query = new URLSearchParams({
      per_page: String(PAGE_SIZE),
      page,
      order_by: "last_activity_at",
      sort: "desc",
      membership: "true",
    });

    const search = options.search?.trim();
    if (search) query.set("search", search);

    const response = await gitlabRequest<GitlabProject[]>(
      connection,
      `/projects?${query.toString()}`,
    );

    if (!response.ok) {
      throw new GitlabApiError(
        describeHttpError(connection, response.raw, response.status),
        response.status,
      );
    }

    if (Array.isArray(response.data)) {
      projects.push(...response.data);
    }

    const next = response.headers["x-next-page"];
    page = typeof next === "string" && next !== "" ? next : null;
    pagesFetched += 1;
  }

  return { projects, truncated: page !== null };
}

export type GitlabBranch = {
  name: string;
  default: boolean;
  protected: boolean;
  commit?: { id: string; short_id: string; created_at: string } | null;
};

export async function listBranches(
  connection: GitlabConnection,
  projectId: number,
): Promise<{ branches: GitlabBranch[]; truncated: boolean }> {
  const branches: GitlabBranch[] = [];
  let page: string | null = "1";
  let pagesFetched = 0;

  while (page !== null && pagesFetched < MAX_PAGES) {
    const query = new URLSearchParams({ per_page: String(PAGE_SIZE), page });

    const response = await gitlabRequest<GitlabBranch[]>(
      connection,
      `/projects/${projectId}/repository/branches?${query.toString()}`,
    );

    if (!response.ok) {
      throw new GitlabApiError(
        describeHttpError(connection, response.raw, response.status),
        response.status,
      );
    }

    if (Array.isArray(response.data)) {
      branches.push(...response.data);
    }

    const next = response.headers["x-next-page"];
    page = typeof next === "string" && next !== "" ? next : null;
    pagesFetched += 1;
  }

  return { branches, truncated: page !== null };
}

/**
 * Looks up specific projects by id. Used so a pinned project still appears even
 * when it falls outside the recency window of the listing.
 */
export async function fetchProjectsByIds(
  connection: GitlabConnection,
  ids: number[],
): Promise<GitlabProject[]> {
  const results = await Promise.all(
    ids.slice(0, MAX_PINNED_LOOKUPS).map(async (id) => {
      try {
        const response = await gitlabRequest<GitlabProject>(connection, `/projects/${id}`);
        return response.ok && response.data ? response.data : null;
      } catch {
        return null;
      }
    }),
  );

  return results.filter((project): project is GitlabProject => project !== null);
}

export type GitlabTreeEntry = {
  id: string;
  name: string;
  type: "tree" | "blob";
  path: string;
  mode: string;
};

/** Reads a file's raw contents at a ref. Returns null when the file is absent. */
export async function getFileRaw(
  connection: GitlabConnection,
  projectId: number,
  filePath: string,
  ref: string,
): Promise<string | null> {
  const response = await gitlabRequest<string>(
    connection,
    `/projects/${projectId}/repository/files/${encodeURIComponent(filePath)}/raw?ref=${encodeURIComponent(ref)}`,
  );

  if (response.status === 404) return null;
  if (!response.ok) {
    throw new GitlabApiError(
      describeHttpError(connection, response.raw, response.status),
      response.status,
    );
  }

  // The raw endpoint returns the file body rather than JSON.
  return response.raw;
}

/**
 * Lists one directory level of a repository at a ref. Returns null when the
 * directory does not exist, so "missing" is distinguishable from "empty".
 */
export async function getTree(
  connection: GitlabConnection,
  projectId: number,
  ref: string,
  path = "",
): Promise<GitlabTreeEntry[] | null> {
  const entries: GitlabTreeEntry[] = [];
  let page: string | null = "1";
  let pagesFetched = 0;

  while (page !== null && pagesFetched < MAX_PAGES) {
    const query = new URLSearchParams({
      ref,
      per_page: String(PAGE_SIZE),
      page,
    });
    if (path !== "") query.set("path", path);

    const response = await gitlabRequest<GitlabTreeEntry[]>(
      connection,
      `/projects/${projectId}/repository/tree?${query.toString()}`,
    );

    if (response.status === 404) return null;
    if (!response.ok) {
      throw new GitlabApiError(
        describeHttpError(connection, response.raw, response.status),
        response.status,
      );
    }

    if (Array.isArray(response.data)) {
      entries.push(...response.data);
    }

    const next = response.headers["x-next-page"];
    page = typeof next === "string" && next !== "" ? next : null;
    pagesFetched += 1;
  }

  return entries;
}
