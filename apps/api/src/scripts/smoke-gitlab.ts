/**
 * In-process smoke test for the GitLab connection settings.
 *
 *   $env:PGLITE_DIR="<scratch>/pglite-gitlab"; pnpm --filter @fusion-tester/api exec tsx src/scripts/smoke-gitlab.ts
 *
 * The last two checks reach the network (gitlab.com and a reserved
 * non-resolving domain) to exercise the real HTTP and clone code paths.
 */
import { createHash, randomUUID } from "node:crypto";
import { createServer, type Server } from "node:http";
import { eq } from "drizzle-orm";

import { buildApp } from "../app.js";
import { hashPassword } from "../auth/password.js";
import { config } from "../config.js";
import { decryptSecret, encryptSecret } from "../crypto/secret-box.js";
import { client, db, runMigrations } from "../db/index.js";
import {
  captchaChallenges,
  gitlabConnections,
  ipBlocks,
  loginAttempts,
  pins,
  sessions,
  users,
} from "../db/schema.js";
import { normalizeBaseUrl } from "../gitlab/client.js";
import { humanizeTestName } from "../gitlab/flutter.js";
import { ensureDefaultAdmin } from "../seed.js";

let failures = 0;

function check(label: string, condition: boolean, detail?: unknown): void {
  if (condition) {
    process.stdout.write(`  PASS  ${label}\n`);
    return;
  }
  failures += 1;
  process.stdout.write(
    `  FAIL  ${label}${detail === undefined ? "" : ` -> ${JSON.stringify(detail).slice(0, 300)}`}\n`,
  );
}

const TOKEN = "glpat-FAKETOKEN1234567890";
const STUB_TOKEN = "stub-good-token";

/** pubspec fixtures covering each shape the branch check must tell apart. */
const PUBSPECS: Record<number, string> = {
  1: "name: alpha_app\npublish_to: 'none'\nflutter:\n  uses-material-design: true\n",
  2: "name: alpha_package\nflutter:\n  uses-material-design: true\n",
  3: "name: alpha_plugin\nflutter:\n  plugin:\n    platforms:\n      android:\n        package: com.example.plugin\n",
  4: "name: only_shared\nflutter:\n  uses-material-design: true\n",
  999: "name: zzz_app\nflutter:\n  uses-material-design: true\n",
};

/** Platform directories present at the repository root. */
const PLATFORM_DIRS: Record<number, string[]> = {
  1: ["android", "ios"],
  2: [],
  3: ["android", "ios"],
  4: ["android"],
  999: ["android"],
};

/** Contents of .maestro/flows. A missing key means the directory is absent. */
const FLOWS: Record<number, Array<{ name: string; type: "tree" | "blob" }>> = {
  1: [
    { name: "orders_list", type: "tree" },
    { name: "advanced_setting", type: "tree" },
    { name: "full_app", type: "tree" },
    { name: "shared", type: "tree" },
    { name: "device_login.yaml", type: "blob" },
  ],
  2: [{ name: "smoke", type: "tree" }],
  // Only shared subflows: nothing here is a runnable test.
  4: [{ name: "shared", type: "tree" }],
};

/**
 * A minimal stand-in for the GitLab API. The response shapes mirror the real
 * /api/v4/projects payload, and pagination is driven by x-next-page so the
 * client's page-following logic is genuinely exercised.
 */
function startStubGitlab(): Promise<{ server: Server; port: number }> {
  const server = createServer((req, res) => {
    const url = new URL(req.url ?? "/", "http://localhost");
    res.setHeader("content-type", "application/json");

    const token = req.headers["private-token"];

    if (token === "stub-bad-token") {
      res.statusCode = 401;
      // Deliberately echoes the token so redaction is actually tested.
      res.end(
        JSON.stringify({ message: `401 Unauthorized: token ${String(token)} is invalid` }),
      );
      return;
    }

    if (url.pathname === "/api/v4/personal_access_tokens/self") {
      res.end(
        JSON.stringify({
          id: 1,
          name: "stub",
          scopes: ["read_api", "read_repository"],
          expires_at: null,
          user_id: 1,
        }),
      );
      return;
    }

    if (url.pathname === "/api/v4/user") {
      res.end(JSON.stringify({ id: 1, username: "stub-user", name: "Stub User" }));
      return;
    }

    if (url.pathname === "/api/v4/projects") {
      if ((url.searchParams.get("page") ?? "1") === "1") {
        res.setHeader("x-next-page", "2");
        res.end(
          JSON.stringify([
            {
              id: 1,
              name: "alpha",
              path_with_namespace: "team/alpha",
              default_branch: "main",
              visibility: "private",
              last_activity_at: "2026-01-01T00:00:00Z",
              web_url: "https://gitlab.example.com/team/alpha",
            },
            {
              id: 2,
              name: "beta",
              path_with_namespace: "team/beta",
              default_branch: "develop",
              visibility: "private",
              last_activity_at: "2026-01-02T00:00:00Z",
              web_url: "https://gitlab.example.com/team/beta",
            },
          ]),
        );
        return;
      }

      res.end(
        JSON.stringify([
          {
            id: 3,
            name: "gamma",
            path_with_namespace: "team/gamma",
            default_branch: null,
            visibility: "internal",
            last_activity_at: "2026-01-03T00:00:00Z",
            web_url: "https://gitlab.example.com/team/gamma",
          },
        ]),
      );
      return;
    }

    if (url.pathname === "/api/v4/projects/999") {
      // Deliberately absent from the listing, to exercise pinned lookups.
      res.end(
        JSON.stringify({
          id: 999,
          name: "zzz",
          path_with_namespace: "team/zzz",
          default_branch: "main",
          visibility: "private",
          last_activity_at: "2020-01-01T00:00:00Z",
          web_url: "https://gitlab.example.com/team/zzz",
        }),
      );
      return;
    }

    const branchMatch = /^\/api\/v4\/projects\/(\d+)\/repository\/branches$/.exec(url.pathname);
    if (branchMatch) {
      if (branchMatch[1] === "1") {
        res.end(
          JSON.stringify([
            {
              name: "main",
              default: true,
              protected: true,
              commit: { id: "a1", short_id: "a1", created_at: "2026-09-20T10:00:00Z" },
            },
            {
              name: "develop",
              default: false,
              protected: false,
              commit: { id: "b1", short_id: "b1", created_at: "2026-09-18T10:00:00Z" },
            },
            {
              name: "release/1.0",
              default: false,
              protected: true,
              commit: { id: "c1", short_id: "c1", created_at: "2026-09-10T10:00:00Z" },
            },
          ]),
        );
        return;
      }

      res.end(JSON.stringify([]));
      return;
    }

    const treeMatch = /^\/api\/v4\/projects\/(\d+)\/repository\/tree$/.exec(url.pathname);
    if (treeMatch) {
      const projectId = Number(treeMatch[1]);
      const path = url.searchParams.get("path") ?? "";

      if (path === ".maestro/flows") {
        const flows = FLOWS[projectId];
        if (!flows) {
          res.statusCode = 404;
          res.end(JSON.stringify({ message: "404 Tree Not Found" }));
          return;
        }
        res.end(
          JSON.stringify(
            flows.map((entry, index) => ({
              id: `f${index}`,
              name: entry.name,
              type: entry.type,
              path: `.maestro/flows/${entry.name}`,
              mode: entry.type === "tree" ? "040000" : "100644",
            })),
          ),
        );
        return;
      }

      if (path !== "") {
        res.statusCode = 404;
        res.end(JSON.stringify({ message: "404 Tree Not Found" }));
        return;
      }

      const entries = [
        { id: "p", name: "pubspec.yaml", type: "blob", path: "pubspec.yaml", mode: "100644" },
        ...(PLATFORM_DIRS[projectId] ?? []).map((dir, index) => ({
          id: `d${index}`,
          name: dir,
          type: "tree",
          path: dir,
          mode: "040000",
        })),
      ];
      res.end(JSON.stringify(entries));
      return;
    }

    const fileMatch = /^\/api\/v4\/projects\/(\d+)\/repository\/files\/(.+)\/raw$/.exec(
      url.pathname,
    );
    if (fileMatch) {
      const projectId = Number(fileMatch[1]);
      const filePath = decodeURIComponent(fileMatch[2] ?? "");
      const pubspec = filePath === "pubspec.yaml" ? PUBSPECS[projectId] : undefined;

      if (pubspec === undefined) {
        res.statusCode = 404;
        res.end(JSON.stringify({ message: "404 File Not Found" }));
        return;
      }

      res.setHeader("content-type", "text/plain");
      res.end(pubspec);
      return;
    }

    res.statusCode = 404;
    res.end(JSON.stringify({ message: "404 Not Found" }));
  });

  return new Promise((resolve) => {
    server.listen(0, "127.0.0.1", () => {
      const address = server.address();
      const port = typeof address === "object" && address !== null ? address.port : 0;
      resolve({ server, port });
    });
  });
}

async function main(): Promise<void> {
  await runMigrations();
  await ensureDefaultAdmin();

  await db.delete(loginAttempts);
  await db.delete(ipBlocks);
  await db.delete(captchaChallenges);
  await db.delete(sessions);
  await db.delete(gitlabConnections);
  await db.delete(pins);
  await db.update(users).set({ passwordHash: await hashPassword("admin") });

  const app = await buildApp({ logger: false });
  await app.ready();

  process.stdout.write("\n1. Secret box\n");
  const sealed = encryptSecret(TOKEN);
  check("ciphertext differs from plaintext", !sealed.includes(TOKEN), sealed.slice(0, 24));
  check("round trip returns the original", decryptSecret(sealed) === TOKEN);
  check("two encryptions of the same value differ", encryptSecret(TOKEN) !== sealed);
  let tamperRejected = false;
  try {
    const parts = sealed.split(".");
    parts[3] = `${parts[3]}AA`;
    decryptSecret(parts.join("."));
  } catch {
    tamperRejected = true;
  }
  check("tampered ciphertext is rejected by GCM", tamperRejected);

  process.stdout.write("\n2. Settings endpoints require a session\n");
  const unauthGet = await app.inject({ method: "GET", url: "/settings/gitlab" });
  check("GET /settings/gitlab -> 401", unauthGet.statusCode === 401, unauthGet.statusCode);
  const unauthPut = await app.inject({
    method: "PUT",
    url: "/settings/gitlab",
    payload: { baseUrl: "https://gitlab.example.com", token: TOKEN },
  });
  check("PUT /settings/gitlab -> 401", unauthPut.statusCode === 401, unauthPut.statusCode);
  const unauthTest = await app.inject({ method: "POST", url: "/settings/gitlab/test", payload: {} });
  check("POST /settings/gitlab/test -> 401", unauthTest.statusCode === 401, unauthTest.statusCode);

  // Sign in to get a session.
  const captchaId = randomUUID();
  const answer = "SMOKECODE";
  await db.insert(captchaChallenges).values({
    id: captchaId,
    answerHash: createHash("sha256").update(answer.toLowerCase()).digest("hex"),
    expiresAt: new Date(Date.now() + 600_000),
  });
  const loginResponse = await app.inject({
    method: "POST",
    url: "/auth/login",
    payload: { username: "admin", password: "admin", captchaId, captchaText: answer },
  });
  const cookie = loginResponse.cookies.find((c) => c.name === config.sessionCookieName);
  const auth = { cookie: `${config.sessionCookieName}=${cookie?.value ?? ""}` };
  check("signed in for the rest of the test", loginResponse.statusCode === 200, loginResponse.statusCode);

  process.stdout.write("\n2b. Projects endpoint before GitLab is connected\n");
  const unauthProjects = await app.inject({ method: "GET", url: "/gitlab/projects" });
  check("GET /gitlab/projects without a session -> 401", unauthProjects.statusCode === 401, unauthProjects.statusCode);
  const notConfigured = await app.inject({ method: "GET", url: "/gitlab/projects", headers: auth });
  check("GET /gitlab/projects unconfigured -> 409", notConfigured.statusCode === 409, notConfigured.statusCode);
  check(
    "unconfigured response explains what to do",
    (notConfigured.json() as { error?: string }).error === "not_configured",
    notConfigured.body,
  );

  process.stdout.write("\n3. Base URL normalisation\n");
  check("adds https when missing", normalizeBaseUrl("gitlab.example.com") === "https://gitlab.example.com");
  check("strips trailing slashes", normalizeBaseUrl("https://gitlab.example.com///") === "https://gitlab.example.com");
  check("keeps an explicit http scheme", normalizeBaseUrl("http://gitlab.local") === "http://gitlab.local");

  process.stdout.write("\n4. Save and read back\n");
  const save = await app.inject({
    method: "PUT",
    url: "/settings/gitlab",
    headers: auth,
    payload: { baseUrl: "https://gitlab.example.com/", token: TOKEN, caCertificate: "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----" },
  });
  check("PUT saves -> 200", save.statusCode === 200, save.statusCode);
  check("save response omits the token", !save.body.includes(TOKEN));
  check("save response shows a hint", save.body.includes("7890"), save.body);
  check("base URL normalised on save", (save.json() as { baseUrl?: string }).baseUrl === "https://gitlab.example.com");

  const get = await app.inject({ method: "GET", url: "/settings/gitlab", headers: auth });
  check("GET returns 200", get.statusCode === 200, get.statusCode);
  check("GET never returns the token", !get.body.includes(TOKEN), get.body);
  check("GET reports configured", (get.json() as { configured?: boolean }).configured === true);
  check("GET reports the CA certificate presence", (get.json() as { hasCaCertificate?: boolean }).hasCaCertificate === true);

  const stored = await db.select().from(gitlabConnections).limit(1);
  check("token is stored as ciphertext, not plaintext", stored[0] !== undefined && !stored[0].tokenCiphertext.includes(TOKEN));
  check("stored token decrypts back", stored[0] !== undefined && decryptSecret(stored[0].tokenCiphertext) === TOKEN);

  process.stdout.write("\n5. Saving without a token keeps the stored one\n");
  const resave = await app.inject({
    method: "PUT",
    url: "/settings/gitlab",
    headers: auth,
    payload: { baseUrl: "https://gitlab.example.com" },
  });
  check("PUT without token -> 200", resave.statusCode === 200, resave.statusCode);
  const afterResave = await db.select().from(gitlabConnections).limit(1);
  check(
    "stored token survives a token-less save",
    afterResave[0] !== undefined && decryptSecret(afterResave[0].tokenCiphertext) === TOKEN,
  );
  check(
    "CA certificate survives when omitted",
    afterResave[0]?.caCertificate !== null && afterResave[0]?.caCertificate !== undefined,
  );

  process.stdout.write("\n6. Unreachable host fails cleanly\n");
  const unreachable = await app.inject({
    method: "POST",
    url: "/settings/gitlab/test",
    headers: auth,
    payload: { baseUrl: "https://gitlab.invalid", token: TOKEN, testRepo: "group/project", caCertificate: "" },
  });
  check("unreachable host -> 200 with a result body", unreachable.statusCode === 200, unreachable.statusCode);
  const unreachableBody = unreachable.json() as { ok?: boolean; error?: string | null };
  check("result is not ok", unreachableBody.ok === false);
  check("an error message is present", typeof unreachableBody.error === "string" && unreachableBody.error.length > 0);
  check("unreachable response omits the token", !unreachable.body.includes(TOKEN));

  process.stdout.write("\n7. Real GitLab host with an invalid token\n");
  const badToken = await app.inject({
    method: "POST",
    url: "/settings/gitlab/test",
    headers: auth,
    payload: {
      baseUrl: "https://gitlab.com",
      token: TOKEN,
      testRepo: "gitlab-org/cli",
      caCertificate: "",
    },
  });
  const badBody = badToken.json() as {
    ok?: boolean;
    error?: string | null;
    clone?: { attempted?: boolean; ok?: boolean; message?: string | null };
    warnings?: string[];
  };
  check("invalid token -> result returned", badToken.statusCode === 200, badToken.statusCode);
  check("invalid token is reported as not ok", badBody.ok === false);
  check("401 is explained", (badBody.error ?? "").includes("401"), badBody.error);
  check("clone check actually ran", badBody.clone?.attempted === true);
  check("clone succeeded against the public repo", badBody.clone?.ok === true, badBody.clone?.message);
  check(
    "public-repo caveat is surfaced",
    (badBody.warnings ?? []).some((w) => w.includes("anonymously")),
    badBody.warnings,
  );
  check("response body never contains the token", !badToken.body.includes(TOKEN));

  process.stdout.write("\n8. Project listing against a stub GitLab\n");
  const stub = await startStubGitlab();
  const stubUrl = `http://127.0.0.1:${stub.port}`;

  const connectStub = await app.inject({
    method: "PUT",
    url: "/settings/gitlab",
    headers: auth,
    payload: { baseUrl: stubUrl, token: STUB_TOKEN, caCertificate: "" },
  });
  check("stub connection saved", connectStub.statusCode === 200, connectStub.statusCode);

  const listResponse = await app.inject({
    method: "GET",
    url: "/gitlab/projects",
    headers: auth,
  });
  check("GET /gitlab/projects -> 200", listResponse.statusCode === 200, listResponse.statusCode);

  const listBody = listResponse.json() as {
    truncated?: boolean;
    projects?: Array<{
      id: number;
      name: string;
      pathWithNamespace: string;
      defaultBranch: string | null;
      visibility: string;
      webUrl: string;
    }>;
  };
  const projectList = listBody.projects ?? [];
  const paths = projectList.map((project) => project.pathWithNamespace);

  check("returns every project across both pages", projectList.length === 3, paths);
  check("follows x-next-page to page 2", paths.includes("team/gamma"), paths);
  check("maps path correctly", paths.includes("team/alpha"), paths);
  check("maps default branch", projectList[0]?.defaultBranch === "main", projectList[0]);
  check(
    "preserves a null default branch",
    projectList.find((project) => project.pathWithNamespace === "team/gamma")?.defaultBranch === null,
  );
  check("maps visibility", projectList[0]?.visibility === "private", projectList[0]);
  check("maps web url", projectList[0]?.webUrl === "https://gitlab.example.com/team/alpha", projectList[0]);
  check("reports truncated=false when exhausted", listBody.truncated === false, listBody.truncated);
  check("token never appears in the listing response", !listResponse.body.includes(STUB_TOKEN));

  process.stdout.write("\n9. Upstream failure is redacted\n");
  await db
    .update(gitlabConnections)
    .set({ tokenCiphertext: encryptSecret("stub-bad-token") });

  const badList = await app.inject({ method: "GET", url: "/gitlab/projects", headers: auth });
  check("bad token -> 502", badList.statusCode === 502, badList.statusCode);
  check(
    "the upstream error is surfaced",
    (badList.json() as { message?: string }).message?.includes("401") === true,
    badList.body,
  );
  check(
    "the echoed token is redacted out of the message",
    !badList.body.includes("stub-bad-token"),
    badList.body,
  );

  // Section 9 deliberately corrupted the token; restore it for the rest.
  await db
    .update(gitlabConnections)
    .set({ tokenCiphertext: encryptSecret(STUB_TOKEN) });

  process.stdout.write("\n10. Pins\n");
  const unauthPins = await app.inject({ method: "GET", url: "/pins" });
  check("GET /pins without a session -> 401", unauthPins.statusCode === 401, unauthPins.statusCode);
  const unauthPinWrite = await app.inject({
    method: "PUT",
    url: "/pins",
    payload: { kind: "repository", projectId: 1, projectPath: "team/alpha" },
  });
  check("PUT /pins without a session -> 401", unauthPinWrite.statusCode === 401, unauthPinWrite.statusCode);

  const emptyPins = await app.inject({ method: "GET", url: "/pins", headers: auth });
  check("no pins initially", (emptyPins.json() as { pins?: unknown[] }).pins?.length === 0);

  const pinRepo = await app.inject({
    method: "PUT",
    url: "/pins",
    headers: auth,
    payload: { kind: "repository", projectId: 2, projectPath: "team/beta" },
  });
  check("PUT repository pin -> 200", pinRepo.statusCode === 200, pinRepo.statusCode);

  const pinRepoAgain = await app.inject({
    method: "PUT",
    url: "/pins",
    headers: auth,
    payload: { kind: "repository", projectId: 2, projectPath: "team/beta" },
  });
  check("re-pinning is accepted", pinRepoAgain.statusCode === 200, pinRepoAgain.statusCode);
  const afterDoublePin = await app.inject({ method: "GET", url: "/pins", headers: auth });
  check(
    "re-pinning does not duplicate the row",
    (afterDoublePin.json() as { pins?: unknown[] }).pins?.length === 1,
    afterDoublePin.body,
  );

  const branchPinWithoutBranch = await app.inject({
    method: "PUT",
    url: "/pins",
    headers: auth,
    payload: { kind: "branch", projectId: 1, projectPath: "team/alpha" },
  });
  check("branch pin with no branch -> 400", branchPinWithoutBranch.statusCode === 400, branchPinWithoutBranch.statusCode);

  const pinBranch = await app.inject({
    method: "PUT",
    url: "/pins",
    headers: auth,
    payload: { kind: "branch", projectId: 1, projectPath: "team/alpha", branch: "develop" },
  });
  check("PUT branch pin -> 200", pinBranch.statusCode === 200, pinBranch.statusCode);

  process.stdout.write("\n11. Pinned flags in the projects listing\n");
  const pinnedListing = await app.inject({ method: "GET", url: "/gitlab/projects", headers: auth });
  const pinnedProjects =
    (pinnedListing.json() as { projects?: Array<{ id: number; pinned: boolean }> }).projects ?? [];
  check("pinned project is flagged", pinnedProjects.find((p) => p.id === 2)?.pinned === true, pinnedProjects);
  check("unpinned project is not flagged", pinnedProjects.find((p) => p.id === 1)?.pinned === false, pinnedProjects);
  check(
    "listing keeps natural order (server does not re-sort)",
    pinnedProjects[0]?.id === 1,
    pinnedProjects.map((p) => p.id),
  );

  process.stdout.write("\n12. Branches\n");
  const branchesResponse = await app.inject({
    method: "GET",
    url: "/gitlab/projects/1/branches",
    headers: auth,
  });
  check("GET branches -> 200", branchesResponse.statusCode === 200, branchesResponse.statusCode);

  const branchList =
    (branchesResponse.json() as {
      branches?: Array<{
        name: string;
        default: boolean;
        protected: boolean;
        pinned: boolean;
        lastCommitAt: string | null;
      }>;
    }).branches ?? [];

  check("returns every branch", branchList.length === 3, branchList.map((b) => b.name));
  check("marks the default branch", branchList.find((b) => b.name === "main")?.default === true);
  check("marks a protected branch", branchList.find((b) => b.name === "release/1.0")?.protected === true);
  check(
    "maps the last commit date",
    branchList.find((b) => b.name === "main")?.lastCommitAt === "2026-09-20T10:00:00Z",
    branchList.find((b) => b.name === "main"),
  );
  check("pinned branch is flagged", branchList.find((b) => b.name === "develop")?.pinned === true, branchList);
  check("unpinned branch is not flagged", branchList.find((b) => b.name === "main")?.pinned === false, branchList);

  const nonNumericProject = await app.inject({
    method: "GET",
    url: "/gitlab/projects/abc/branches",
    headers: auth,
  });
  check("non-numeric project id -> 400", nonNumericProject.statusCode === 400, nonNumericProject.statusCode);

  const noBranches = await app.inject({
    method: "GET",
    url: "/gitlab/projects/103/branches",
    headers: auth,
  });
  check(
    "project with no branches -> empty list",
    (noBranches.json() as { branches?: unknown[] }).branches?.length === 0,
    noBranches.body,
  );

  process.stdout.write("\n13. Pinned project outside the fetched window\n");
  await app.inject({
    method: "PUT",
    url: "/pins",
    headers: auth,
    payload: { kind: "repository", projectId: 999, projectPath: "team/zzz" },
  });
  const outsideWindow = await app.inject({ method: "GET", url: "/gitlab/projects", headers: auth });
  const outsideProjects =
    (outsideWindow.json() as { projects?: Array<{ id: number; pinned: boolean }> }).projects ?? [];
  check(
    "pinned project missing from the listing is looked up and returned",
    outsideProjects.some((p) => p.id === 999),
    outsideProjects.map((p) => p.id),
  );
  check(
    "and it is flagged pinned",
    outsideProjects.find((p) => p.id === 999)?.pinned === true,
  );

  process.stdout.write("\n14. Unpinning\n");
  const unpinRepo = await app.inject({
    method: "DELETE",
    url: "/pins?kind=repository&projectId=2",
    headers: auth,
  });
  check("DELETE repository pin -> 200", unpinRepo.statusCode === 200, unpinRepo.statusCode);
  check("removed reports true", (unpinRepo.json() as { removed?: boolean }).removed === true);

  const afterUnpin = await app.inject({ method: "GET", url: "/gitlab/projects", headers: auth });
  const afterUnpinProjects =
    (afterUnpin.json() as { projects?: Array<{ id: number; pinned: boolean }> }).projects ?? [];
  check("pinned flag is cleared", afterUnpinProjects.find((p) => p.id === 2)?.pinned === false, afterUnpinProjects);

  const unpinMissing = await app.inject({
    method: "DELETE",
    url: "/pins?kind=repository&projectId=2",
    headers: auth,
  });
  check(
    "unpinning something that is not pinned is harmless",
    unpinMissing.statusCode === 200 && (unpinMissing.json() as { removed?: boolean }).removed === false,
    unpinMissing.body,
  );

  const unpinBranch = await app.inject({
    method: "DELETE",
    url: "/pins?kind=branch&projectId=1&branch=develop",
    headers: auth,
  });
  check("DELETE branch pin -> 200", unpinBranch.statusCode === 200, unpinBranch.statusCode);
  const branchesAfterUnpin = await app.inject({
    method: "GET",
    url: "/gitlab/projects/1/branches",
    headers: auth,
  });
  check(
    "branch pinned flag is cleared",
    ((branchesAfterUnpin.json() as { branches?: Array<{ name: string; pinned: boolean }> }).branches ?? []).find(
      (b) => b.name === "develop",
    )?.pinned === false,
  );

  process.stdout.write("\n15. Test name humanising\n");
  check("orders_list -> Orders list", humanizeTestName("orders_list") === "Orders list");
  check("full_app -> Full app", humanizeTestName("full_app") === "Full app");
  check("single word is capitalised", humanizeTestName("smoke") === "Smoke");
  check("already capitalised is untouched", humanizeTestName("Orders") === "Orders");
  check(
    "multiple underscores only capitalise the first letter",
    humanizeTestName("mark_ready_complete") === "Mark ready complete",
    humanizeTestName("mark_ready_complete"),
  );

  process.stdout.write("\n16. Branch check\n");
  type CheckBody = {
    isFlutterApp?: boolean;
    hasMaestroFlows?: boolean;
    canContinue?: boolean;
    isFlutterAppReason?: string;
    hasMaestroFlowsReason?: string;
  };

  const unauthCheck = await app.inject({
    method: "GET",
    url: "/gitlab/projects/1/branch-check?ref=main",
  });
  check("branch-check without a session -> 401", unauthCheck.statusCode === 401, unauthCheck.statusCode);

  const missingRef = await app.inject({
    method: "GET",
    url: "/gitlab/projects/1/branch-check",
    headers: auth,
  });
  check("branch-check without a ref -> 400", missingRef.statusCode === 400, missingRef.statusCode);

  const appCheck = await app.inject({
    method: "GET",
    url: "/gitlab/projects/1/branch-check?ref=main",
    headers: auth,
  });
  const appBody = appCheck.json() as CheckBody;
  check("Flutter app + flows -> 200", appCheck.statusCode === 200, appCheck.statusCode);
  check("recognised as a Flutter application", appBody.isFlutterApp === true, appBody);
  check("flows folder found", appBody.hasMaestroFlows === true, appBody);
  check("continue is allowed", appBody.canContinue === true, appBody);

  const packageCheck = await app.inject({
    method: "GET",
    url: "/gitlab/projects/2/branch-check?ref=main",
    headers: auth,
  });
  const packageBody = packageCheck.json() as CheckBody;
  check("a Flutter package is rejected", packageBody.isFlutterApp === false, packageBody);
  check(
    "package reason explains why",
    (packageBody.isFlutterAppReason ?? "").toLowerCase().includes("package"),
    packageBody.isFlutterAppReason,
  );
  check("package cannot continue", packageBody.canContinue === false, packageBody);

  const pluginCheck = await app.inject({
    method: "GET",
    url: "/gitlab/projects/3/branch-check?ref=main",
    headers: auth,
  });
  const pluginBody = pluginCheck.json() as CheckBody;
  check("a Flutter plugin is rejected", pluginBody.isFlutterApp === false, pluginBody);
  check(
    "plugin reason explains why",
    (pluginBody.isFlutterAppReason ?? "").toLowerCase().includes("plugin"),
    pluginBody.isFlutterAppReason,
  );
  check("plugin has no flows folder", pluginBody.hasMaestroFlows === false, pluginBody);

  const noFlowsCheck = await app.inject({
    method: "GET",
    url: "/gitlab/projects/999/branch-check?ref=main",
    headers: auth,
  });
  const noFlowsBody = noFlowsCheck.json() as CheckBody;
  check("app without flows is still a Flutter app", noFlowsBody.isFlutterApp === true, noFlowsBody);
  check("missing flows folder is detected", noFlowsBody.hasMaestroFlows === false, noFlowsBody);
  check("cannot continue without flows", noFlowsBody.canContinue === false, noFlowsBody);

  const sharedOnlyCheck = await app.inject({
    method: "GET",
    url: "/gitlab/projects/4/branch-check?ref=main",
    headers: auth,
  });
  const sharedOnlyBody = sharedOnlyCheck.json() as CheckBody;
  check(
    "a flows folder holding only shared subflows is not testable",
    sharedOnlyBody.hasMaestroFlows === false,
    sharedOnlyBody,
  );
  check(
    "the reason names the shared folder",
    (sharedOnlyBody.hasMaestroFlowsReason ?? "").includes("shared"),
    sharedOnlyBody.hasMaestroFlowsReason,
  );
  check("shared-only cannot continue", sharedOnlyBody.canContinue === false, sharedOnlyBody);

  const refWithSlash = await app.inject({
    method: "GET",
    url: "/gitlab/projects/1/branch-check?ref=release%2F1.0",
    headers: auth,
  });
  check("branch names containing a slash are accepted", refWithSlash.statusCode === 200, refWithSlash.statusCode);

  process.stdout.write("\n17. Test listing\n");
  const testsResponse = await app.inject({
    method: "GET",
    url: "/gitlab/projects/1/tests?ref=main",
    headers: auth,
  });
  check("GET tests -> 200", testsResponse.statusCode === 200, testsResponse.statusCode);

  const testList =
    (testsResponse.json() as {
      tests?: Array<{ name: string; displayName: string; path: string; exclusive: boolean }>;
    }).tests ?? [];

  check("lists only test folders", testList.length === 3, testList.map((t) => t.name));
  check(
    "whole-suite test sorts to the top, ahead of alphabetically earlier tests",
    testList[0]?.name === "full_app",
    testList.map((t) => t.name),
  );
  check(
    "remaining tests stay alphabetical",
    testList.slice(1).map((t) => t.displayName).join(",") === "Advanced setting,Orders list",
    testList.map((t) => t.displayName),
  );
  check("excludes loose yaml files", !testList.some((t) => t.name.endsWith(".yaml")), testList.map((t) => t.name));
  check("excludes the shared subflow folder", !testList.some((t) => t.name === "shared"), testList.map((t) => t.name));
  check("humanises folder names", testList.some((t) => t.displayName === "Orders list"), testList.map((t) => t.displayName));
  check("keeps the raw folder name alongside", testList.some((t) => t.name === "orders_list"));
  check("includes the folder path", testList.some((t) => t.path === ".maestro/flows/full_app"), testList);
  check(
    "marks full_app as a whole-suite test",
    testList.find((t) => t.name === "full_app")?.exclusive === true,
    testList,
  );
  check(
    "ordinary tests are not marked whole-suite",
    testList.find((t) => t.name === "orders_list")?.exclusive === false,
    testList,
  );

  const noTests = await app.inject({
    method: "GET",
    url: "/gitlab/projects/3/tests?ref=main",
    headers: auth,
  });
  check(
    "absent flows folder -> empty list",
    (noTests.json() as { tests?: unknown[] }).tests?.length === 0,
    noTests.body,
  );

  const unauthTests = await app.inject({ method: "GET", url: "/gitlab/projects/1/tests?ref=main" });
  check("tests without a session -> 401", unauthTests.statusCode === 401, unauthTests.statusCode);

  stub.server.close();

  await app.close();

  process.stdout.write(
    failures === 0 ? "\nAll checks passed.\n" : `\n${failures} check(s) FAILED.\n`,
  );
  process.exitCode = failures === 0 ? 0 : 1;
}

main()
  .then(async () => {
    await client.close();
  })
  .catch(async (error: unknown) => {
    process.stderr.write(`${String(error)}\n`);
    await client.close();
    process.exit(1);
  });
