import { cp, mkdir, readdir, readFile, writeFile } from "node:fs/promises";
import { existsSync } from "node:fs";
import { join, relative } from "node:path";

import { runCommand, type CommandResult } from "./command.js";

export const MAESTRO_DIR = ".maestro";

/** Maestro suites can run for a while; give them more room than a build. */
const MAESTRO_TIMEOUT_MS = 20 * 60 * 1000;

export type MaestroSummary = {
  tests: number | null;
  failures: number | null;
  errors: number | null;
};

export type MaestroResult = {
  ok: boolean;
  output: string;
  flows: string[];
  summary: MaestroSummary;
  /** PNGs Maestro captured, copied into the run workspace. */
  screenshots: string[];
};

/**
 * Picks the flow files for a test folder. `full_test.yaml` is the repository
 * convention for a folder's entry point; otherwise every flow directly inside
 * the folder is used.
 */
export async function resolveTestFlows(
  maestroRoot: string,
  tests: string[],
): Promise<{ flows: string[]; missing: string[] }> {
  const flows: string[] = [];
  const missing: string[] = [];

  for (const test of tests) {
    const folder = join(maestroRoot, "flows", test);

    const conventional = join(folder, "full_test.yaml");
    if (existsSync(conventional)) {
      flows.push(conventional);
      continue;
    }

    if (existsSync(folder)) {
      const entries = await readdir(folder, { withFileTypes: true });
      const yamlFiles = entries
        .filter((entry) => entry.isFile() && /\.ya?ml$/i.test(entry.name))
        .map((entry) => join(folder, entry.name))
        .sort();

      if (yamlFiles.length > 0) {
        flows.push(...yamlFiles);
        continue;
      }
    }

    missing.push(test);
  }

  return { flows, missing };
}

/**
 * The repository's flows target Android (`appId:`). For the web lane the header
 * has to name a URL instead, because Maestro uses it as the session identifier.
 *
 * `clearState: true` is also neutralised for production runs: wiping app data
 * there would remove the credentials the app needs to sign in at all.
 */
export function rewriteFlowForWeb(
  source: string,
  appUrl: string,
  isProduction: boolean,
): string {
  const eol = source.includes("\r\n") ? "\r\n" : "\n";
  let output = source;

  if (/^\s*appId\s*:/m.test(output)) {
    output = output.replace(/^(\s*)appId\s*:.*$/m, `$1url: ${appUrl}`);
  } else if (!/^\s*url\s*:/m.test(output)) {
    // A flow with no header at all still needs one to know what to launch.
    output = `url: ${appUrl}${eol}---${eol}${output}`;
  }

  if (isProduction) {
    output = output.replace(/clearState\s*:\s*true/g, "clearState: false");
  }

  return output;
}

/**
 * Copies the repository's `.maestro` workspace into the run directory and
 * retargets every flow at the served web app. Copying (rather than editing in
 * place) keeps relative `runFlow: ../shared/...` references working while
 * leaving the checkout untouched.
 */
export async function prepareMaestroWorkspace(
  repoDir: string,
  runDir: string,
  appUrl: string,
  isProduction: boolean,
): Promise<string> {
  const source = join(repoDir, MAESTRO_DIR);
  if (!existsSync(source)) {
    throw new Error(`No ${MAESTRO_DIR} directory in the repository.`);
  }

  const target = join(runDir, MAESTRO_DIR);
  await mkdir(runDir, { recursive: true });
  await cp(source, target, { recursive: true });

  const rewrite = async (directory: string): Promise<void> => {
    const entries = await readdir(directory, { withFileTypes: true });
    for (const entry of entries) {
      const full = join(directory, entry.name);
      if (entry.isDirectory()) {
        await rewrite(full);
        continue;
      }
      if (!/\.ya?ml$/i.test(entry.name)) continue;

      const original = await readFile(full, "utf8");
      const rewritten = rewriteFlowForWeb(original, appUrl, isProduction);
      if (rewritten !== original) {
        await writeFile(full, rewritten, "utf8");
      }
    }
  };

  await rewrite(target);
  return target;
}

function readSummary(xml: string): MaestroSummary {
  const read = (name: string): number | null => {
    const match = new RegExp(`\\b${name}="(\\d+)"`).exec(xml);
    return match?.[1] !== undefined ? Number(match[1]) : null;
  };

  return { tests: read("tests"), failures: read("failures"), errors: read("errors") };
}

/** Recursively collects PNG paths under a directory. */
async function findPngs(directory: string): Promise<string[]> {
  if (!existsSync(directory)) return [];

  const found: string[] = [];
  const walk = async (current: string): Promise<void> => {
    const entries = await readdir(current, { withFileTypes: true });
    for (const entry of entries) {
      const full = join(current, entry.name);
      if (entry.isDirectory()) {
        await walk(full);
      } else if (/\.png$/i.test(entry.name)) {
        found.push(full);
      }
    }
  };

  await walk(directory);
  return found.sort();
}

/**
 * Runs the selected flows headlessly against the served app.
 *
 * Maestro's own artifacts are written to `--test-output-dir`; any screenshots
 * it captured are copied next to the run so the dashboard can show what failed.
 */
export async function runMaestro(options: {
  runDir: string;
  workspaceDir: string;
  flows: string[];
  appUrl: string;
  artifactDir: string;
}): Promise<MaestroResult> {
  const { runDir, workspaceDir, flows, appUrl, artifactDir } = options;

  await mkdir(artifactDir, { recursive: true });
  const reportPath = join(runDir, "maestro-results.xml");

  const args = [
    "test",
    "--headless",
    "--no-ansi",
    "--format",
    "junit",
    "--output",
    reportPath,
    "--test-output-dir",
    artifactDir,
    "-e",
    `APP_URL=${appUrl}`,
    ...flows,
  ];

  const result: CommandResult = await runCommand(
    "maestro",
    args,
    workspaceDir,
    MAESTRO_TIMEOUT_MS,
  );

  let summary: MaestroSummary = { tests: null, failures: null, errors: null };
  if (existsSync(reportPath)) {
    try {
      summary = readSummary(await readFile(reportPath, "utf8"));
    } catch {
      // A malformed report should not mask the run result.
    }
  }

  const passed = result.ok && (summary.failures ?? 0) === 0 && (summary.errors ?? 0) === 0;

  // Maestro writes its own screenshots under the artifacts directory. They are
  // what the flow captured with takeScreenshot on success, and what Maestro
  // captured at the failing step on failure — so name them by outcome.
  const captured = await findPngs(artifactDir);
  const screenshots: string[] = [];
  for (const [index, source] of captured.entries()) {
    const name = passed
      ? `maestro-${index + 1}.png`
      : index === 0
        ? "maestro-failure.png"
        : `maestro-failure-${index + 1}.png`;
    await cp(source, join(runDir, name));
    screenshots.push(name);
  }

  return {
    ok: passed,
    output: result.output,
    flows: flows.map((flow) => relative(workspaceDir, flow).replace(/\\/g, "/")),
    summary,
    screenshots,
  };
}
