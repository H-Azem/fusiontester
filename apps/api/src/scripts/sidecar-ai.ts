/**
 * Sidecar: runs the AI test lane for one run.
 *
 * The lane decides actions from the app's accessibility tree and verifies each
 * step with jev, which needs a browser. Like the browse check, that capability
 * lives here and the Laravel service shells out to it.
 *
 * Usage: node --import tsx src/scripts/sidecar-ai.ts
 * Reads {"url","repoDir","runDir","tests","maxSteps","config"} as JSON on stdin
 * and prints one line of JSON on stdout.
 */
import { buildAiMission, runAiTest, type AiTestResult } from "../runs/aiTest.js";
import { findBrowserExecutable } from "../runs/webApp.js";
import type { AiConfig } from "../ai/client.js";

type Payload = {
  url?: string;
  repoDir?: string;
  runDir?: string;
  tests?: string[];
  maxSteps?: number;
  config?: Partial<AiConfig>;
};

function report(payload: Record<string, unknown>): void {
  process.stdout.write(`${JSON.stringify(payload)}\n`);
}

async function readStdin(): Promise<string> {
  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) chunks.push(chunk as Buffer);
  return Buffer.concat(chunks).toString("utf8");
}

try {
  const payload = JSON.parse(await readStdin()) as Payload;

  const executablePath = findBrowserExecutable();
  if (!executablePath) {
    report({ ok: false, error: "No Chrome or Chromium found. Set CHROME_PATH to the browser executable." });
    process.exit(0);
  }

  const config = payload.config;
  if (!config?.openaiBaseUrl || !config.openaiModel || !config.openaiToken || !config.jevToken) {
    report({ ok: false, error: "The AI connection is not fully configured." });
    process.exit(0);
  }

  if (!payload.url || !payload.repoDir || !payload.runDir) {
    report({ ok: false, error: "url, repoDir and runDir are required." });
    process.exit(0);
  }

  const mission = await buildAiMission(payload.repoDir, payload.tests ?? []);

  const result: AiTestResult = await runAiTest({
    runDir: payload.runDir,
    appUrl: payload.url,
    executablePath,
    config: {
      openaiBaseUrl: config.openaiBaseUrl,
      openaiModel: config.openaiModel,
      openaiToken: config.openaiToken,
      jevBaseUrl: config.jevBaseUrl ?? "https://api.typesafe.ai",
      jevToken: config.jevToken,
      maxSteps: payload.maxSteps ?? config.maxSteps ?? 25,
    },
    mission,
  });

  report({
    ok: result.ok,
    summary: result.summary,
    steps: result.steps,
    diagnosis: result.diagnosis,
    screenshot: result.screenshot ? result.screenshot.toString("base64") : null,
  });
} catch (error) {
  report({ ok: false, error: error instanceof Error ? error.message : String(error) });
}
