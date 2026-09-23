import { existsSync } from "node:fs";
import { mkdir, rm, writeFile } from "node:fs/promises";
import { join } from "node:path";
import { and, asc, eq, inArray } from "drizzle-orm";

import { config } from "../config.js";
import { db } from "../db/index.js";
import { runSteps, runs } from "../db/schema.js";
import { getStoredConnection, redact, type GitlabConnection } from "../gitlab/client.js";
import { runCommand } from "./command.js";
import { readLaunchTarget } from "./flutterProject.js";
import {
  prepareMaestroWorkspace,
  resolveTestFlows,
  runMaestro,
} from "./maestro.js";
import { checkAppInBrowser, findBrowserExecutable, serveDirectory } from "./webApp.js";

const OUTPUT_TAIL_CHARS = 4000;

export type RunStage = { key: string; label: string };

/** The stages a run moves through, in order. */
export const RUN_STAGES: RunStage[] = [
  { key: "queued", label: "Added to queue, waiting to start…" },
  { key: "fetch", label: "Getting source…" },
  { key: "packages", label: "Getting packages…" },
  { key: "launch", label: "Reading launch configuration…" },
  { key: "build", label: "Building the web app…" },
  { key: "browse", label: "Opening in a browser…" },
  { key: "maestro", label: "Running Maestro tests…" },
  { key: "done", label: "Done" },
];

function tail(text: string): string {
  const trimmed = text.trim();
  if (trimmed.length <= OUTPUT_TAIL_CHARS) return trimmed;
  return `…${trimmed.slice(-OUTPUT_TAIL_CHARS)}`;
}

function buildCloneUrl(connection: GitlabConnection, projectPath: string): string {
  const parsed = new URL(connection.baseUrl);
  const basePath = parsed.pathname.replace(/\/+$/, "");
  const path = projectPath.replace(/^\/+/, "").replace(/\.git$/, "");

  // Credentials only make sense for HTTP(S) remotes.
  const isHttp = parsed.protocol === "http:" || parsed.protocol === "https:";
  const auth = isHttp ? `oauth2:${encodeURIComponent(connection.token)}@` : "";

  return `${parsed.protocol}//${auth}${parsed.host}${basePath}/${path}.git`;
}

export function workspaceFor(runId: string): string {
  return join(config.dataDir, "runs", runId);
}

/** Where the browser screenshot for a run is kept. */
export function screenshotFor(runId: string): string {
  return join(workspaceFor(runId), "screenshot.png");
}

export function hasScreenshot(runId: string): boolean {
  return existsSync(screenshotFor(runId));
}

/** Maestro's own failure screenshot, when it captured one. */
export function maestroScreenshotFor(runId: string): string {
  return join(workspaceFor(runId), "maestro-failure.png");
}

export function hasMaestroScreenshot(runId: string): boolean {
  return existsSync(maestroScreenshotFor(runId));
}

/**
 * Maestro finds Flutter web elements through the semantics overlay, which is
 * off unless the app asks for it. Most apps gate that call behind a compile-time
 * flag, so every web build requests it; a project can add its own defines on top.
 */
const BASE_DART_DEFINES = ["ENABLE_SEMANTICS=true"];

/**
 * Builds the --dart-define arguments for a web build. Malformed entries are
 * dropped rather than forwarded, because one bad value fails the whole build.
 */
export function dartDefineArgs(projectDefines: string): string[] {
  const extra = projectDefines
    .split(/[\s,]+/)
    .map((entry) => entry.trim())
    .filter((entry) => /^[A-Za-z_][A-Za-z0-9_]*=.*$/.test(entry));

  return [...BASE_DART_DEFINES, ...extra].map((entry) => `--dart-define=${entry}`);
}

export async function enqueueRun(input: {
  projectId: number;
  projectPath: string;
  branch: string;
  tests: string[];
  runKinds: string[];
  environments: string[];
  orientation: string;
  dartDefines: string;
}): Promise<string> {
  const inserted = await db
    .insert(runs)
    .values({
      projectId: input.projectId,
      projectPath: input.projectPath,
      branch: input.branch,
      tests: input.tests,
      runKinds: input.runKinds,
      environments: input.environments,
      orientation: input.orientation,
      dartDefines: input.dartDefines,
    })
    .returning({ id: runs.id });

  const runId = inserted[0]?.id;
  if (!runId) throw new Error("Failed to create run");

  // Pre-create every stage so the dashboard can show the whole path immediately.
  await db.insert(runSteps).values(
    RUN_STAGES.map((stage, index) => ({
      runId,
      key: stage.key,
      label: stage.label,
      position: index,
    })),
  );

  return runId;
}

async function setStep(
  runId: string,
  key: string,
  values: Partial<{ status: string; output: string; startedAt: Date; finishedAt: Date }>,
): Promise<void> {
  await db
    .update(runSteps)
    .set(values)
    .where(and(eq(runSteps.runId, runId), eq(runSteps.key, key)));
}

async function startStage(runId: string, key: string): Promise<void> {
  await db.update(runs).set({ currentStep: key }).where(eq(runs.id, runId));
  await setStep(runId, key, { status: "running", startedAt: new Date() });
}

async function finishStage(runId: string, key: string, output: string): Promise<void> {
  await setStep(runId, key, { status: "done", output: tail(output), finishedAt: new Date() });
}

async function failRun(runId: string, key: string, message: string): Promise<void> {
  await setStep(runId, key, { status: "failed", output: tail(message), finishedAt: new Date() });
  await db
    .update(runs)
    .set({
      status: "failed",
      currentStep: key,
      errorMessage: tail(message).slice(0, 1000),
      finishedAt: new Date(),
    })
    .where(eq(runs.id, runId));
}

/**
 * Runs one job to completion: fetch the source, resolve packages. The Maestro
 * execution itself lands in a later step.
 */
export async function executeRun(runId: string): Promise<void> {
  const rows = await db.select().from(runs).where(eq(runs.id, runId)).limit(1);
  const run = rows[0];
  if (!run) return;

  const connection = await getStoredConnection();
  if (!connection) {
    await failRun(runId, "queued", "GitLab is not connected, so the source cannot be fetched.");
    return;
  }

  const workspace = workspaceFor(run.id);
  const repoDir = join(workspace, "repo");

  // Queueing is over as soon as the worker picks the run up.
  await finishStage(runId, "queued", "Started.");

  try {
    await startStage(runId, "fetch");
    await rm(workspace, { recursive: true, force: true });
    await mkdir(workspace, { recursive: true });

    const clone = await runCommand(
      "git",
      [
        "clone",
        "--depth",
        "1",
        "--single-branch",
        "--branch",
        run.branch,
        buildCloneUrl(connection, run.projectPath),
        repoDir,
      ],
      workspace,
    );

    if (!clone.ok) {
      await failRun(runId, "fetch", redact(clone.output, connection.token));
      return;
    }
    await finishStage(runId, "fetch", clone.output || `Cloned ${run.branch}.`);

    await startStage(runId, "packages");
    const pubGet = await runCommand("flutter", ["pub", "get"], repoDir);
    if (!pubGet.ok) {
      await failRun(runId, "packages", pubGet.output);
      return;
    }
    await finishStage(runId, "packages", pubGet.output);

    // The entry point comes from the repository's own launch configuration, so
    // the run matches what a developer would start locally.
    await startStage(runId, "launch");
    let target;
    try {
      target = await readLaunchTarget(repoDir);
    } catch (error) {
      await failRun(runId, "launch", error instanceof Error ? error.message : String(error));
      return;
    }
    await finishStage(runId, "launch", `Using "${target.name}" → ${target.program}`);

    await startStage(runId, "build");
    const build = await runCommand(
      "flutter",
      ["build", "web", "-t", target.program, ...dartDefineArgs(run.dartDefines)],
      repoDir,
    );
    if (!build.ok) {
      await failRun(runId, "build", build.output);
      return;
    }
    await finishStage(runId, "build", build.output || "Web build succeeded.");

    await startStage(runId, "browse");
    const browserPath = findBrowserExecutable();
    if (!browserPath) {
      await failRun(
        runId,
        "browse",
        "No Chrome or Chromium found. Set CHROME_PATH to the browser executable.",
      );
      return;
    }

    const server = await serveDirectory(join(repoDir, "build", "web"));
    try {
      const check = await checkAppInBrowser(server.url, browserPath);

      // Kept on disk next to the checkout so the dashboard can show what the
      // app looked like, whether it passed or failed.
      if (check.screenshot) {
        await writeFile(screenshotFor(run.id), check.screenshot);
      }

      if (!check.booted) {
        await failRun(
          runId,
          "browse",
          check.errors.join("\n") || "The app never started in the browser.",
        );
        return;
      }
      if (!check.ok) {
        await failRun(runId, "browse", check.errors.join("\n"));
        return;
      }
      await finishStage(
        runId,
        "browse",
        `Opened ${server.url} — the app started without errors.${
          check.screenshot ? " Screenshot captured." : ""
        }`,
      );

      // The app is only useful once it is served, so Maestro runs inside this
      // block while the static server is still up.
      if (run.runKinds.includes("maestro")) {
        await startStage(runId, "maestro");

        const isProduction = run.environments.includes("production");
        const maestroRoot = await prepareMaestroWorkspace(
          repoDir,
          workspace,
          server.url,
          isProduction,
        );

        const { flows, missing } = await resolveTestFlows(maestroRoot, run.tests);
        if (missing.length > 0) {
          await failRun(
            runId,
            "maestro",
            `No flow files found for: ${missing.join(", ")}.`,
          );
          return;
        }
        if (flows.length === 0) {
          await failRun(runId, "maestro", "No tests were selected for this run.");
          return;
        }

        const maestro = await runMaestro({
          runDir: workspace,
          workspaceDir: workspace,
          flows,
          appUrl: server.url,
          artifactDir: join(workspace, "maestro-artifacts"),
        });

        const counts =
          maestro.summary.tests !== null
            ? `${maestro.summary.tests - (maestro.summary.failures ?? 0)}/${maestro.summary.tests} passed`
            : "results unreadable";

        if (!maestro.ok) {
          await failRun(
            runId,
            "maestro",
            `Maestro reported failures (${counts}).\n\n${maestro.output}`,
          );
          return;
        }

        await finishStage(
          runId,
          "maestro",
          `Ran ${maestro.flows.length} flow(s): ${counts}.\n\n${maestro.output}`,
        );
      } else {
        await db
          .update(runSteps)
          .set({ status: "skipped", output: "Skipped — Maestro was not selected." })
          .where(and(eq(runSteps.runId, runId), eq(runSteps.key, "maestro")));
      }
    } finally {
      await server.close();
    }

    await startStage(runId, "done");
    await finishStage(runId, "done", "Test passed.");
    await db
      .update(runs)
      .set({ status: "passed", currentStep: "done", finishedAt: new Date() })
      .where(eq(runs.id, runId));
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    const current = await db
      .select({ currentStep: runs.currentStep })
      .from(runs)
      .where(eq(runs.id, runId))
      .limit(1);
    await failRun(runId, current[0]?.currentStep ?? "fetch", redact(message, connection.token));
  }
}

/** Steps for a run, in order. */
export async function stepsFor(runIds: string[]) {
  if (runIds.length === 0) return [];
  return db
    .select()
    .from(runSteps)
    .where(inArray(runSteps.runId, runIds))
    .orderBy(asc(runSteps.position));
}
