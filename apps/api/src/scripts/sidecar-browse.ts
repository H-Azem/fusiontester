/**
 * Sidecar: opens a served bundle in a real browser and reports whether the app
 * actually started.
 *
 * Kept in the Node package on purpose. Reading a Flutter web app's runtime
 * state needs the browser's accessibility tree over CDP, which is what Maestro
 * and the AI lane already rely on here; the Laravel service shells out to this
 * script instead of reimplementing it.
 *
 * Usage: node --import tsx src/scripts/sidecar-browse.ts <url>
 * Prints one line of JSON on stdout.
 */
import { checkAppInBrowser, findBrowserExecutable } from "../runs/webApp.js";

const url = process.argv[2];

function report(payload: Record<string, unknown>): void {
  process.stdout.write(`${JSON.stringify(payload)}\n`);
}

if (!url) {
  report({ ok: false, error: "No URL was provided to the browse sidecar." });
  process.exit(0);
}

const executablePath = findBrowserExecutable();
if (!executablePath) {
  report({
    ok: false,
    error: "No Chrome or Chromium found. Set CHROME_PATH to the browser executable.",
  });
  process.exit(0);
}

try {
  const check = await checkAppInBrowser(url, executablePath);

  report({
    ok: check.ok,
    booted: check.booted,
    errors: check.errors,
    screenshot: check.screenshot ? check.screenshot.toString("base64") : null,
  });
} catch (error) {
  report({
    ok: false,
    error: error instanceof Error ? error.message : String(error),
  });
}
