/**
 * Smoke test for the run queue.
 *
 *   $env:PGLITE_DIR="<scratch>/pglite-runs"; pnpm --filter @fusion-tester/api exec tsx src/scripts/smoke-runs.ts
 *
 * Drives the real worker against a local git fixture, so it runs offline and
 * needs no private dependencies. Generates a real Flutter web app, so it builds
 * and boots an app for real — expect a couple of minutes.
 */
import { createHash, randomUUID } from "node:crypto";
import { mkdir, rm, writeFile } from "node:fs/promises";
import { join } from "node:path";
import { eq } from "drizzle-orm";

import { buildApp } from "../app.js";
import { hashPassword } from "../auth/password.js";
import { config } from "../config.js";
import { encryptSecret, secretHint } from "../crypto/secret-box.js";
import { client, db, runMigrations } from "../db/index.js";
import {
  captchaChallenges,
  gitlabConnections,
  ipBlocks,
  loginAttempts,
  runSteps,
  runs,
  sessions,
  users,
} from "../db/schema.js";
import { startRunWorker } from "../runs/worker.js";
import { workspaceFor } from "../runs/pipeline.js";
import { runCommand } from "../runs/command.js";
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

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Builds a real, minimal Flutter web app in a bare git repo. Generating it with
 * `flutter create` means the build and browser stages run for real, while the
 * app itself has no dependencies so this stays offline and reproducible.
 *
 * "General Production" is deliberately listed first so the test proves the
 * "first configuration containing develop" rule skips it.
 */
async function createFixtureRepo(): Promise<{ root: string; projectPath: string }> {
  const root = join(config.dataDir, "test-fixtures");
  const workDir = join(root, "demo-app");
  const bareDir = join(root, "demo-app.git");

  await rm(root, { recursive: true, force: true });
  await mkdir(root, { recursive: true });

  const created = await runCommand(
    "flutter",
    ["create", "--platforms=web", "--project-name", "demo_app", workDir],
    root,
    300_000,
  );
  if (!created.ok) throw new Error(`flutter create failed:\n${created.output}`);

  await mkdir(join(workDir, ".vscode"), { recursive: true });
  await writeFile(
    join(workDir, ".vscode", "launch.json"),
    JSON.stringify(
      {
        version: "0.2.0",
        configurations: [
          {
            name: "General Production",
            request: "launch",
            type: "dart",
            program: "lib/main_production.dart",
          },
          {
            name: "General Development",
            request: "launch",
            type: "dart",
            program: "lib/main.dart",
          },
          {
            name: "SDK Development",
            request: "launch",
            type: "dart",
            program: "lib/main_sdk.dart",
          },
        ],
      },
      null,
      4,
    ),
    "utf8",
  );

  await runCommand("git", ["init", "-b", "main"], workDir);
  await runCommand("git", ["add", "."], workDir);
  await runCommand(
    "git",
    ["-c", "user.email=smoke@example.com", "-c", "user.name=Smoke Test", "commit", "-m", "fixture"],
    workDir,
  );
  await runCommand("git", ["clone", "--bare", workDir, bareDir], root);

  return { root: root.replace(/\\/g, "/"), projectPath: "demo-app" };
}

async function main(): Promise<void> {
  // Keep the browser settle short; the fixture app boots in well under this.
  process.env.APP_BOOT_SETTLE_MS = "5000";

  await runMigrations();
  await db.delete(runSteps);
  await db.delete(runs);
  await db.delete(sessions);
  await db.delete(loginAttempts);
  await db.delete(ipBlocks);
  await db.delete(captchaChallenges);
  await db.delete(gitlabConnections);

  // The admin user has to exist before the password can be pinned.
  await ensureDefaultAdmin();
  await db
    .update(users)
    .set({ passwordHash: await hashPassword("admin") })
    .where(eq(users.username, "admin"));

  // A file:// remote keeps the pipeline fully offline and deterministic.
  const fixture = await createFixtureRepo();
  await db.insert(gitlabConnections).values({
    baseUrl: `file:///${fixture.root}`,
    tokenCiphertext: encryptSecret("unused-for-local-remotes"),
    tokenHint: secretHint("unused-for-local-remotes"),
    caCertificate: null,
  });

  const captchaId = randomUUID();
  const answer = "RUNCODE";
  await db.insert(captchaChallenges).values({
    id: captchaId,
    answerHash: createHash("sha256").update(answer.toLowerCase()).digest("hex"),
    expiresAt: new Date(Date.now() + 600_000),
  });

  const app = await buildApp({ logger: false });
  await app.ready();

  const login = await app.inject({
    method: "POST",
    url: "/auth/login",
    payload: { username: "admin", password: "admin", captchaId, captchaText: answer },
  });
  const cookie = login.cookies.find((c) => c.name === config.sessionCookieName);
  const auth = { cookie: `${config.sessionCookieName}=${cookie?.value ?? ""}` };
  check("signed in", login.statusCode === 200, login.statusCode);

  process.stdout.write("\n1. Authorisation\n");
  const unauthCreate = await app.inject({ method: "POST", url: "/runs", payload: {} });
  check("POST /runs without a session -> 401", unauthCreate.statusCode === 401, unauthCreate.statusCode);
  const unauthList = await app.inject({ method: "GET", url: "/runs" });
  check("GET /runs without a session -> 401", unauthList.statusCode === 401, unauthList.statusCode);

  const badBody = await app.inject({
    method: "POST",
    url: "/runs",
    headers: auth,
    payload: { projectId: 1, projectPath: "x", branch: "main", runKinds: [], environments: [] },
  });
  check("empty runKinds rejected -> 400", badBody.statusCode === 400, badBody.statusCode);

  process.stdout.write("\n2. Queueing\n");
  const created = await app.inject({
    method: "POST",
    url: "/runs",
    headers: auth,
    payload: {
      projectId: 101,
      projectPath: fixture.projectPath,
      branch: "main",
      tests: ["orders", "full_app"],
      runKinds: ["maestro"],
      environments: ["development", "production"],
    },
  });
  check("POST /runs -> 201", created.statusCode === 201, created.statusCode);

  const firstRunId = (created.json() as { id?: string }).id;
  check("run has an id", typeof firstRunId === "string");

  const createdBody = created.json() as {
    status?: string;
    steps?: Array<{ key: string; label: string; status: string }>;
  };
  check("new run is queued", createdBody.status === "queued", createdBody.status);
  check("all seven stages are pre-created", createdBody.steps?.length === 7, createdBody.steps);
  check(
    "stages start pending, in the expected order",
    createdBody.steps?.every((step) => step.status === "pending") === true &&
      createdBody.steps?.map((step) => step.key).join(",") ===
        "queued,fetch,packages,launch,build,browse,done",
    createdBody.steps?.map((step) => step.key),
  );
  check(
    "queue label matches the agreed wording",
    createdBody.steps?.[0]?.label === "Added to queue, waiting to start…",
    createdBody.steps?.[0],
  );
  check(
    "packages label matches the agreed wording",
    createdBody.steps?.[2]?.label === "Getting packages…",
    createdBody.steps?.[2],
  );

  process.stdout.write("\n3. Single-flight queue\n");
  const worker = startRunWorker();

  // Wait for the worker to pick the first run up.
  let firstStatus = "";
  for (let i = 0; i < 60; i++) {
    const res = await app.inject({ method: "GET", url: `/runs/${firstRunId}`, headers: auth });
    firstStatus = (res.json() as { status?: string }).status ?? "";
    if (firstStatus !== "queued") break;
    await sleep(500);
  }
  check("worker picked the run up", firstStatus === "running", firstStatus);

  // A second run queued behind a running one must wait.
  const queued = await app.inject({
    method: "POST",
    url: "/runs",
    headers: auth,
    payload: {
      projectId: 102,
      projectPath: "does-not-exist",
      branch: "main",
      tests: ["smoke"],
      runKinds: ["maestro"],
      environments: ["development"],
    },
  });
  const secondRunId = (queued.json() as { id?: string }).id;
  check("second run queued", queued.statusCode === 201, queued.statusCode);

  const secondNow = await app.inject({ method: "GET", url: `/runs/${secondRunId}`, headers: auth });
  check(
    "second run waits while the first is running",
    (secondNow.json() as { status?: string }).status === "queued",
    (secondNow.json() as { status?: string }).status,
  );

  process.stdout.write("\n4. Full pipeline (clone + flutter pub get)\n");
  let first: {
    status?: string;
    currentStep?: string;
    hasScreenshot?: boolean;
    steps?: Array<{ key: string; status: string; output: string | null }>;
  } = {};
  for (let i = 0; i < 240; i++) {
    const res = await app.inject({ method: "GET", url: `/runs/${firstRunId}`, headers: auth });
    first = res.json() as typeof first;
    if (first.status !== "running" && first.status !== "queued") break;
    await sleep(1000);
  }

  check("first run passes", first.status === "passed", { status: first.status, steps: first.steps?.map((s) => [s.key, s.status]) });

  const stepMap = Object.fromEntries(
    (first.steps ?? []).map((step) => [step.key, step]),
  ) as Record<string, { status: string; output: string | null }>;

  check("queued stage done", stepMap.queued?.status === "done", stepMap);
  check("fetch stage done", stepMap.fetch?.status === "done", stepMap);
  check("packages stage done", stepMap.packages?.status === "done", stepMap);
  check("launch stage done", stepMap.launch?.status === "done", stepMap);
  check("build stage done", stepMap.build?.status === "done", stepMap);
  check("browse stage done", stepMap.browse?.status === "done", stepMap);
  check("done stage done", stepMap.done?.status === "done", stepMap);

  check(
    "pub get output captured",
    (stepMap.packages?.output ?? "").length > 0,
    stepMap.packages?.output?.slice(0, 200),
  );
  check(
    "launch config picked the first entry point containing develop",
    (stepMap.launch?.output ?? "").includes("General Development") &&
      (stepMap.launch?.output ?? "").includes("lib/main.dart"),
    stepMap.launch?.output,
  );
  check(
    "the web build produced output",
    (stepMap.build?.output ?? "").length > 0,
    stepMap.build?.output?.slice(0, 200),
  );
  check(
    "the app started in a browser without errors",
    (stepMap.browse?.output ?? "").includes("started without errors"),
    stepMap.browse?.output,
  );
  check(
    "the run reports the test passed",
    (stepMap.done?.output ?? "").includes("Test passed"),
    stepMap.done?.output,
  );

  process.stdout.write("\n4b. Screenshot\n");
  check("run reports it has a screenshot", first.hasScreenshot === true, first.hasScreenshot);

  const shot = await app.inject({
    method: "GET",
    url: `/runs/${firstRunId}/screenshot`,
    headers: auth,
  });
  check("screenshot is served -> 200", shot.statusCode === 200, shot.statusCode);

  const magic = shot.rawPayload.subarray(0, 4);
  check(
    "screenshot is a real PNG",
    magic[0] === 0x89 && magic[1] === 0x50 && magic[2] === 0x4e && magic[3] === 0x47,
    [...magic],
  );
  check("screenshot has real content", shot.rawPayload.length > 1000, shot.rawPayload.length);

  const unauthShot = await app.inject({ method: "GET", url: `/runs/${firstRunId}/screenshot` });
  check("screenshot without a session -> 401", unauthShot.statusCode === 401, unauthShot.statusCode);

  process.stdout.write("\n5. Failure path\n");
  let second: { status?: string; errorMessage?: string | null; steps?: Array<{ key: string; status: string }> } = {};
  for (let i = 0; i < 120; i++) {
    const res = await app.inject({ method: "GET", url: `/runs/${secondRunId}`, headers: auth });
    second = res.json() as typeof second;
    if (second.status !== "running" && second.status !== "queued") break;
    await sleep(1000);
  }
  check("second run fails on a bad remote", second.status === "failed", second.status);
  check(
    "fetch stage marked failed",
    ((second.steps ?? []).find((s) => s.key === "fetch")?.status) === "failed",
    second.steps,
  );
  check("error message recorded", (second.errorMessage ?? "").length > 0, second.errorMessage);
  check(
    "downstream stages left pending",
    ((second.steps ?? []).find((s) => s.key === "packages")?.status) === "pending",
    second.steps,
  );

  const noShot = await app.inject({
    method: "GET",
    url: `/runs/${secondRunId}/screenshot`,
    headers: auth,
  });
  check(
    "a run that never reached the browser has no screenshot -> 404",
    noShot.statusCode === 404,
    noShot.statusCode,
  );

  process.stdout.write("\n6. Listing\n");
  const list = await app.inject({ method: "GET", url: "/runs", headers: auth });
  const listed = (list.json() as { runs?: Array<{ id: string; steps: unknown[] }> }).runs ?? [];
  check("GET /runs returns both runs", listed.length === 2, listed.length);
  check("listing includes steps for detail view", listed.every((run) => run.steps.length === 7), listed.map((r) => r.steps.length));
  check("newest run listed first", listed[0]?.id === secondRunId, listed.map((r) => r.id));

  await app.close();
  // Stop polling before the database closes, otherwise the loop spams errors.
  worker.stop();

  process.stdout.write(
    failures === 0 ? "\nAll checks passed.\n" : `\n${failures} check(s) FAILED.\n`,
  );
  process.exitCode = failures === 0 ? 0 : 1;
}

main()
  .then(async () => {
    // Remove cloned workspaces so the test leaves nothing large behind.
    // SMOKE_KEEP_WORKSPACES=1 keeps them, which is handy when inspecting a failure.
    if (process.env.SMOKE_KEEP_WORKSPACES !== "1") {
      const all = await db.select({ id: runs.id }).from(runs);
      for (const run of all) {
        await rm(workspaceFor(run.id), { recursive: true, force: true }).catch(() => undefined);
      }
    }
    await client.close();
  })
  .catch(async (error: unknown) => {
    process.stderr.write(`${String(error)}\n`);
    await client.close();
    process.exit(1);
  });
