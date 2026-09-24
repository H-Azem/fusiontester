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
import { mkdir, readFile, readdir, rm, writeFile } from "node:fs/promises";
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
  projectSettings,
  runSteps,
  runs,
  sessions,
  users,
} from "../db/schema.js";
import { startRunWorker } from "../runs/worker.js";
import { commandLine, dartDefineArgs, RUN_STAGES, workspaceFor } from "../runs/pipeline.js";
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

  // Maestro finds Flutter web elements through the semantics overlay, which is
  // off by default. Without this the flows cannot see anything.
  //
  // This is gated behind ENABLE_SEMANTICS on purpose: it mirrors how real apps
  // ship the call, so the suite fails if the build stops passing the define.
  const mainDartPath = join(workDir, "lib", "main.dart");
  const mainDart = await readFile(mainDartPath, "utf8");
  await writeFile(
    mainDartPath,
    mainDart
      .replace(
        "import 'package:flutter/material.dart';",
        "import 'package:flutter/material.dart';\nimport 'package:flutter/rendering.dart';",
      )
      .replace(
        "void main() {",
        [
          "void main() {",
          "  WidgetsFlutterBinding.ensureInitialized();",
          "  if (const bool.fromEnvironment('ENABLE_SEMANTICS')) {",
          "    SemanticsBinding.instance.ensureSemantics();",
          "  }",
        ].join("\n"),
      ),
    "utf8",
  );

  // Two flows: one that should pass and one that must fail, so both the
  // success and failure paths are covered.
  await mkdir(join(workDir, ".maestro", "flows", "smoke"), { recursive: true });
  await mkdir(join(workDir, ".maestro", "flows", "broken"), { recursive: true });

  await writeFile(
    join(workDir, ".maestro", "flows", "smoke", "full_test.yaml"),
    [
      "appId: com.example.demo_app",
      "---",
      "- launchApp",
      '- assertVisible: "Flutter Demo Home Page"',
      "- takeScreenshot: smoke_shot",
      "",
    ].join("\n"),
    "utf8",
  );
  await writeFile(
    join(workDir, ".maestro", "flows", "broken", "full_test.yaml"),
    [
      "appId: com.example.demo_app",
      "---",
      "- launchApp",
      '- assertVisible: "A label that does not exist anywhere"',
      "",
    ].join("\n"),
    "utf8",
  );
  await writeFile(
    join(workDir, ".maestro", "config.yaml"),
    ["env:", "  APP_ID: com.example.demo_app", ""].join("\n"),
    "utf8",
  );

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
  await db.delete(projectSettings);

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
      tests: ["smoke"],
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
  // Derived from the pipeline so adding a stage cannot silently stale this.
  check(
    "every stage is pre-created",
    createdBody.steps?.length === RUN_STAGES.length,
    createdBody.steps?.length,
  );
  check(
    "stages start pending, in the expected order",
    createdBody.steps?.every((step) => step.status === "pending") === true &&
      createdBody.steps?.map((step) => step.key).join(",") ===
        RUN_STAGES.map((stage) => stage.key).join(","),
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

  check(
    "a web build always asks for the semantics overlay",
    dartDefineArgs("").includes("--dart-define=ENABLE_SEMANTICS=true"),
    dartDefineArgs(""),
  );
  // Compared against the built-in set rather than a literal list, so this keeps
  // testing what it means to test when the built-ins change.
  const builtIn = dartDefineArgs("").join(" ");
  check(
    "project defines are appended to the built-in ones",
    dartDefineArgs("A=1 B=2").join(" ") === `${builtIn} --dart-define=A=1 --dart-define=B=2`,
    dartDefineArgs("A=1 B=2"),
  );
  check(
    "a malformed define is dropped instead of failing the build",
    dartDefineArgs("nonsense with space=1").join(" ") === `${builtIn} --dart-define=space=1`,
    dartDefineArgs("nonsense with space=1"),
  );
  check(
    "a command is rendered the way it would be typed",
    commandLine("flutter", ["build", "web", "-t", "lib/main.dart"]) ===
      "$ flutter build web -t lib/main.dart",
    commandLine("flutter", ["build", "web", "-t", "lib/main.dart"]),
  );

  const freshSettings = (
    await app.inject({ method: "GET", url: "/projects/103/settings", headers: auth })
  ).json() as { orientation?: string };
  check(
    "a project with no saved preference defaults to horizontal",
    freshSettings.orientation === "horizontal",
    freshSettings,
  );
  const settingsNoSession = await app.inject({ method: "GET", url: "/projects/103/settings" });
  check("project settings require a session", settingsNoSession.statusCode === 401, settingsNoSession.statusCode);

  let first: {
    status?: string;
    currentStep?: string;
    hasScreenshot?: boolean;
    orientation?: string;
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
  check("maestro stage done", stepMap.maestro?.status === "done", stepMap);
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
    "the build stage shows the exact command it ran",
    (stepMap.build?.output ?? "").startsWith("$ flutter build web -t lib/main.dart"),
    stepMap.build?.output?.slice(0, 120),
  );
  check(
    "the shown command includes the semantics define",
    (stepMap.build?.output ?? "").includes("--dart-define=ENABLE_SEMANTICS=true"),
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
  check(
    "maestro ran the selected flow and reported a pass",
    (stepMap.maestro?.output ?? "").includes("1/1 passed"),
    stepMap.maestro?.output?.slice(0, 300),
  );

  process.stdout.write("\n4b. Screenshot\n");
  check("run reports it has a screenshot", first.hasScreenshot === true, first.hasScreenshot);

  const workspaceFiles = await readdir(workspaceFor(String(firstRunId))).catch(() => [] as string[]);
  check(
    "a passing run keeps Maestro's screenshots under the success names",
    workspaceFiles.some((name) => /^maestro-\d+\.png$/.test(name)),
    workspaceFiles.filter((name) => name.endsWith(".png")),
  );
  check(
    "a passing run has no failure screenshot",
    !workspaceFiles.includes("maestro-failure.png"),
    workspaceFiles.filter((name) => name.endsWith(".png")),
  );

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
  check(
    "listing includes steps for detail view",
    listed.every((run) => run.steps.length === RUN_STAGES.length),
    listed.map((r) => r.steps.length),
  );
  check("newest run listed first", listed[0]?.id === secondRunId, listed.map((r) => r.id));

  process.stdout.write("\n7. Maestro failure\n");
  const failing = await app.inject({
    method: "POST",
    url: "/runs",
    headers: auth,
    payload: {
      projectId: 103,
      projectPath: fixture.projectPath,
      branch: "main",
      tests: ["broken"],
      runKinds: ["maestro"],
      environments: ["development"],
      orientation: "vertical",
      dartDefines: "ENABLE_DEV_TOOLS=true",
    },
  });
  const failingRunId = (failing.json() as { id?: string }).id;
  check("failing run queued", failing.statusCode === 201, failing.statusCode);

  let third: {
    status?: string;
    errorMessage?: string | null;
    orientation?: string;
    hasMaestroScreenshot?: boolean;
    steps?: Array<{ key: string; status: string; output: string | null }>;
  } = {};
  for (let i = 0; i < 240; i++) {
    const res = await app.inject({ method: "GET", url: `/runs/${failingRunId}`, headers: auth });
    third = res.json() as typeof third;
    if (third.status !== "running" && third.status !== "queued") break;
    await sleep(1000);
  }

  check("a failing flow fails the run", third.status === "failed", {
    status: third.status,
    steps: third.steps?.map((s) => [s.key, s.status]),
  });
  check(
    "maestro stage marked failed",
    third.steps?.find((s) => s.key === "maestro")?.status === "failed",
    third.steps,
  );
  check(
    "downstream stage left pending",
    third.steps?.find((s) => s.key === "done")?.status === "pending",
    third.steps,
  );
  check(
    "failure output quoted in the error",
    (third.errorMessage ?? "").includes("Maestro reported failures"),
    third.errorMessage?.slice(0, 200),
  );
  check(
    "maestro failure screenshot captured",
    third.hasMaestroScreenshot === true,
    third.hasMaestroScreenshot,
  );

  if (third.hasMaestroScreenshot) {
    const shotFail = await app.inject({
      method: "GET",
      url: `/runs/${failingRunId}/maestro-screenshot`,
      headers: auth,
    });
    check("maestro screenshot is served -> 200", shotFail.statusCode === 200, shotFail.statusCode);
    const magicFail = shotFail.rawPayload.subarray(0, 4);
    check(
      "maestro screenshot is a real PNG",
      magicFail[0] === 0x89 && magicFail[1] === 0x50 && magicFail[2] === 0x4e && magicFail[3] === 0x47,
      [...magicFail],
    );
  }

  process.stdout.write("\n8. Orientation preference\n");
  check(
    "omitting orientation defaults the run to horizontal",
    first.orientation === "horizontal",
    first.orientation,
  );
  check("the run records the orientation it was started with", third.orientation === "vertical", third.orientation);

  const savedSettings = (
    await app.inject({ method: "GET", url: "/projects/103/settings", headers: auth })
  ).json() as { orientation?: string };
  check(
    "starting a run saves the choice for that repository",
    savedSettings.orientation === "vertical",
    savedSettings,
  );

  const otherProject = (
    await app.inject({ method: "GET", url: "/projects/104/settings", headers: auth })
  ).json() as { orientation?: string };
  check(
    "the choice stays scoped to its own repository",
    otherProject.orientation === "horizontal",
    otherProject,
  );

  const defaultDefines = (
    await app.inject({ method: "GET", url: "/projects/104/settings", headers: auth })
  ).json() as { dartDefines?: string };
  check("a project starts with no extra defines", defaultDefines.dartDefines === "", defaultDefines);

  const savedDefines = (
    await app.inject({ method: "GET", url: "/projects/103/settings", headers: auth })
  ).json() as { dartDefines?: string };
  check(
    "extra defines are remembered for the repository",
    savedDefines.dartDefines === "ENABLE_DEV_TOOLS=true",
    savedDefines,
  );

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
