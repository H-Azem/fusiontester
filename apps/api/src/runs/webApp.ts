import { createReadStream, existsSync, statSync } from "node:fs";
import { createServer, type Server } from "node:http";
import { extname, join, normalize } from "node:path";
import puppeteer from "puppeteer-core";

const MIME: Record<string, string> = {
  ".html": "text/html; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
  ".mjs": "text/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".map": "application/json; charset=utf-8",
  ".wasm": "application/wasm",
  ".css": "text/css; charset=utf-8",
  ".png": "image/png",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".gif": "image/gif",
  ".svg": "image/svg+xml",
  ".ico": "image/x-icon",
  ".ttf": "font/ttf",
  ".otf": "font/otf",
  ".woff": "font/woff",
  ".woff2": "font/woff2",
  ".bin": "application/octet-stream",
};

/**
 * Console text that means the app failed to start. Flutter web reports Dart
 * errors through console output, and apps commonly render their own error
 * screen, so a clean page is not on its own proof that the app came up.
 */
const FAILURE_PATTERNS = [
  /unsupported operation/i,
  /app initialization failed/i,
  /uncaught/i,
  /unhandled exception/i,
];

const DEFAULT_SETTLE_MS = 10_000;

/** Chrome/Chromium is required to actually run the app; it is not bundled. */
export function findBrowserExecutable(): string | null {
  const override = process.env.CHROME_PATH;
  if (override && existsSync(override)) return override;

  const candidates =
    process.platform === "win32"
      ? [
          `${process.env["ProgramFiles"]}\\Google\\Chrome\\Application\\chrome.exe`,
          `${process.env["ProgramFiles(x86)"]}\\Google\\Chrome\\Application\\chrome.exe`,
          `${process.env["LOCALAPPDATA"]}\\Google\\Chrome\\Application\\chrome.exe`,
          `${process.env["ProgramFiles"]}\\Microsoft\\Edge\\Application\\msedge.exe`,
          `${process.env["ProgramFiles(x86)"]}\\Microsoft\\Edge\\Application\\msedge.exe`,
        ]
      : [
          "/usr/bin/google-chrome",
          "/usr/bin/google-chrome-stable",
          "/usr/bin/chromium",
          "/usr/bin/chromium-browser",
          "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
        ];

  return candidates.find((candidate) => candidate && existsSync(candidate)) ?? null;
}

export type StaticServer = {
  url: string;
  close: () => Promise<void>;
};

/** Serves a built Flutter web bundle on an ephemeral local port. */
export async function serveDirectory(root: string): Promise<StaticServer> {
  if (!existsSync(root)) {
    throw new Error(`Nothing to serve: ${root} does not exist.`);
  }

  const server: Server = createServer((req, res) => {
    const url = new URL(req.url ?? "/", "http://localhost");
    const relative = normalize(decodeURIComponent(url.pathname)).replace(/^(\.\.[/\\])+/, "");
    let filePath = join(root, relative);

    if (!existsSync(filePath) || statSync(filePath).isDirectory()) {
      // Flutter web is a single-page app.
      filePath = join(root, "index.html");
    }

    res.setHeader(
      "content-type",
      MIME[extname(filePath).toLowerCase()] ?? "application/octet-stream",
    );
    createReadStream(filePath).pipe(res);
  });

  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));

  const address = server.address();
  const port = typeof address === "object" && address !== null ? address.port : 0;

  return {
    url: `http://127.0.0.1:${port}/`,
    close: () =>
      new Promise<void>((resolve) => {
        server.close(() => resolve());
      }),
  };
}

export type BrowserCheck = {
  ok: boolean;
  booted: boolean;
  errors: string[];
  /** PNG of the app as it loaded, or null if capture failed. */
  screenshot: Buffer | null;
};

/**
 * Loads the app and reports whether it came up cleanly. `booted` distinguishes
 * "Flutter never started" from "Flutter started and then failed".
 */
export async function checkAppInBrowser(
  url: string,
  executablePath: string,
): Promise<BrowserCheck> {
  const settleMs = Number(process.env.APP_BOOT_SETTLE_MS ?? DEFAULT_SETTLE_MS);

  const browser = await puppeteer.launch({
    executablePath,
    headless: true,
    args: ["--no-sandbox", "--disable-dev-shm-usage", "--disable-gpu"],
  });

  try {
    const page = await browser.newPage();
    const errors: string[] = [];

    page.on("pageerror", (error: unknown) => {
      errors.push(
        `Page error: ${error instanceof Error ? error.message : String(error)}`,
      );
    });

    page.on("console", (message: { type: () => string; text: () => string }) => {
      const text = message.text();
      if (message.type() === "error" || FAILURE_PATTERNS.some((pattern) => pattern.test(text))) {
        errors.push(text.trim());
      }
    });

    await page.goto(url, { waitUntil: "domcontentloaded", timeout: 60_000 });

    // Give the Dart app time to boot and surface any startup failure.
    await new Promise((resolve) => setTimeout(resolve, settleMs));

    // Read via globalThis so this Node project does not need the DOM lib.
    const booted = await page.evaluate(() => {
      const doc = (
        globalThis as unknown as { document?: { querySelector(selector: string): unknown } }
      ).document;
      return doc ? doc.querySelector("flutter-view, flt-glass-pane") !== null : false;
    });

    // Captured even when the app failed, so a failure shows what was on screen.
    let screenshot: Buffer | null = null;
    try {
      screenshot = Buffer.from(await page.screenshot({ type: "png" }));
    } catch {
      screenshot = null;
    }

    const unique = [...new Set(errors)].filter((line) => line !== "");

    return { ok: unique.length === 0, booted, errors: unique, screenshot };
  } finally {
    await browser.close();
  }
}
